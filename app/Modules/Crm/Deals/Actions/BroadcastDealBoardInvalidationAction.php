<?php

namespace App\Modules\Crm\Deals\Actions;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Rbac\Models\EntityCollaborator;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Deals\Events\SiloBoardInvalidated;
use App\Modules\Crm\Deals\Models\Deal;
use Illuminate\Support\Facades\DB;
use Throwable;

class BroadcastDealBoardInvalidationAction
{
    /**
     * Register a DB::afterCommit callback that resolves deduplicated active deals.view recipients
     * and dispatches ID-only SiloBoardInvalidated broadcasts without ever failing the caller.
     */
    public function afterCommit(Deal $deal): void
    {
        $dealId = (string) $deal->id;
        $pipelineId = (string) $deal->pipeline_id;
        $stageId = (string) $deal->stage_id;
        $boardPosition = (float) $deal->board_position;
        $version = (int) $deal->version;
        $ownerId = (string) $deal->owner_id;
        $accountManagerId = $deal->account_manager_id !== null ? (string) $deal->account_manager_id : null;

        DB::afterCommit(function () use (
            $dealId,
            $pipelineId,
            $stageId,
            $boardPosition,
            $version,
            $ownerId,
            $accountManagerId,
        ): void {
            try {
                $recipientIds = $this->resolveAuthorizedRecipientIds($dealId, $ownerId, $accountManagerId);

                foreach ($recipientIds as $recipientUserId) {
                    try {
                        event(new SiloBoardInvalidated(
                            recipientUserId: $recipientUserId,
                            dealId: $dealId,
                            pipelineId: $pipelineId,
                            stageId: $stageId,
                            boardPosition: $boardPosition,
                            version: $version,
                        ));
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * @return list<string>
     */
    public function resolveAuthorizedRecipientIds(
        string $dealId,
        string $ownerId,
        ?string $accountManagerId,
    ): array {
        $collaboratorUserIds = EntityCollaborator::query()
            ->where('collaboratable_type', 'deal')
            ->where('collaboratable_id', $dealId)
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $ownerAncestorIds = DB::table('user_hierarchy_closures')
            ->where('descendant_id', $ownerId)
            ->pluck('ancestor_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $candidateIds = array_values(array_unique(array_filter([
            $ownerId,
            $accountManagerId,
            ...$collaboratorUserIds,
            ...$ownerAncestorIds,
        ], static fn (?string $id): bool => $id !== null && $id !== '')));

        if ($candidateIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $candidateIds)
            ->where('status', UserStatusEnum::Active->value)
            ->with(['roles.permissions', 'permissions'])
            ->get()
            ->filter(static fn (User $user): bool => ! $user->trashed()
                && ($user->hasRole(RoleEnum::Admin->value) || $user->checkPermissionTo(PermissionEnum::DealsView->value)))
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }
}
