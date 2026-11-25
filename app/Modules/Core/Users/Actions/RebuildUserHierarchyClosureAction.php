<?php

namespace App\Modules\Core\Users\Actions;

use App\Modules\Core\Users\Models\User;
use App\Support\Exceptions\HierarchyCycleException;
use App\Support\Silo\VisibleScopeResolver;
use Illuminate\Support\Facades\DB;

class RebuildUserHierarchyClosureAction
{
    public function __construct(
        protected VisibleScopeResolver $visibleScopeResolver,
    ) {}

    public function execute(User $user): void
    {
        /** @var list<string> $affectedUserIds */
        $affectedUserIds = [];

        DB::transaction(function () use ($user, &$affectedUserIds): void {
            $userId = (string) $user->id;
            $newManagerId = $user->manager_id !== null ? (string) $user->manager_id : null;

            $this->assertNoCycle($userId, $newManagerId);

            if ($user->exists && $user->isDirty('manager_id')) {
                $user->save();
            }

            DB::table('user_hierarchy_closures')->insertOrIgnore([
                'ancestor_id' => $userId,
                'descendant_id' => $userId,
                'depth' => 0,
            ]);

            /** @var list<string> $oldAncestorIds */
            $oldAncestorIds = DB::table('user_hierarchy_closures')
                ->where('descendant_id', $userId)
                ->where('ancestor_id', '!=', $userId)
                ->pluck('ancestor_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();

            $subtreeRows = DB::table('user_hierarchy_closures')
                ->where('ancestor_id', $userId)
                ->get(['descendant_id', 'depth']);

            /** @var list<string> $subtreeIds */
            $subtreeIds = $subtreeRows
                ->pluck('descendant_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();

            if ($oldAncestorIds !== [] && $subtreeIds !== []) {
                DB::table('user_hierarchy_closures')
                    ->whereIn('ancestor_id', $oldAncestorIds)
                    ->whereIn('descendant_id', $subtreeIds)
                    ->delete();
            }

            /** @var list<string> $newAncestorIds */
            $newAncestorIds = [];

            if ($newManagerId !== null) {
                DB::table('user_hierarchy_closures')->insertOrIgnore([
                    'ancestor_id' => $newManagerId,
                    'descendant_id' => $newManagerId,
                    'depth' => 0,
                ]);

                $managerAncestors = DB::table('user_hierarchy_closures')
                    ->where('descendant_id', $newManagerId)
                    ->get(['ancestor_id', 'depth']);

                $rowsToUpsert = [];
                foreach ($managerAncestors as $ancestorRow) {
                    $ancestorId = (string) $ancestorRow->ancestor_id;
                    $newAncestorIds[] = $ancestorId;

                    foreach ($subtreeRows as $descendantRow) {
                        $rowsToUpsert[] = [
                            'ancestor_id' => $ancestorId,
                            'descendant_id' => (string) $descendantRow->descendant_id,
                            'depth' => (int) $ancestorRow->depth + 1 + (int) $descendantRow->depth,
                        ];
                    }
                }

                if ($rowsToUpsert !== []) {
                    foreach (array_chunk($rowsToUpsert, 500) as $chunk) {
                        DB::table('user_hierarchy_closures')->upsert(
                            $chunk,
                            ['ancestor_id', 'descendant_id'],
                            ['depth'],
                        );
                    }
                }
            }

            $affectedUserIds = array_values(array_unique([
                $userId,
                ...$oldAncestorIds,
                ...$newAncestorIds,
            ]));
        });

        $this->visibleScopeResolver->invalidateForUsers($affectedUserIds);
    }

    protected function assertNoCycle(string $userId, ?string $newManagerId): void
    {
        if ($newManagerId === null) {
            return;
        }

        if ($newManagerId === $userId) {
            throw new HierarchyCycleException('A user cannot report to themselves.');
        }

        $isClosureDescendant = DB::table('user_hierarchy_closures')
            ->where('ancestor_id', $userId)
            ->where('descendant_id', $newManagerId)
            ->exists();

        if ($isClosureDescendant) {
            throw new HierarchyCycleException('Cannot assign a subordinate as a manager (reporting cycle detected).');
        }

        // Secondary guard walking users.manager_id in case closures were not yet built for an intermediate node.
        $visited = [$userId => true];
        $cursor = $newManagerId;

        while ($cursor !== null) {
            if (isset($visited[$cursor])) {
                throw new HierarchyCycleException('Cannot create a reporting hierarchy cycle.');
            }

            $visited[$cursor] = true;
            $parentId = DB::table('users')->where('id', $cursor)->value('manager_id');
            $cursor = $parentId !== null ? (string) $parentId : null;
        }
    }
}
