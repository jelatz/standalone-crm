<?php

namespace App\Modules\Projects\Handoffs\Actions;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Events\HandoffStatusChanged;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use Illuminate\Support\Facades\DB;
use Throwable;

class BroadcastHandoffStatusAction
{
    /**
     * Register a DB::afterCommit callback notifying active users holding handoffs.review.
     */
    public function afterCommitToReviewers(DealHandoff $handoff): void
    {
        $handoffId = (string) $handoff->id;
        $dealId = (string) $handoff->deal_id;
        $status = $handoff->status;

        DB::afterCommit(function () use ($handoffId, $dealId, $status): void {
            try {
                $recipientIds = $this->resolveReviewerRecipientIds();
                $this->dispatchToRecipients($recipientIds, $handoffId, $status, $dealId);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Register a DB::afterCommit callback notifying active submitter, assigned PM, and assigned AM.
     */
    public function afterCommitToStakeholders(DealHandoff $handoff): void
    {
        $handoffId = (string) $handoff->id;
        $dealId = (string) $handoff->deal_id;
        $status = $handoff->status;
        $candidateIds = array_values(array_unique(array_filter([
            (string) $handoff->submitted_by_id,
            $handoff->assigned_pm_id !== null ? (string) $handoff->assigned_pm_id : null,
            $handoff->assigned_am_id !== null ? (string) $handoff->assigned_am_id : null,
        ], static fn (?string $id): bool => $id !== null && $id !== '')));

        DB::afterCommit(function () use ($candidateIds, $handoffId, $dealId, $status): void {
            try {
                $recipientIds = $this->resolveStakeholderRecipientIds($candidateIds);
                $this->dispatchToRecipients($recipientIds, $handoffId, $status, $dealId);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * @return list<string>
     */
    public function resolveReviewerRecipientIds(): array
    {
        return User::query()
            ->where('status', UserStatusEnum::Active->value)
            ->permission(PermissionEnum::HandoffsReview->value)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $candidateIds
     * @return list<string>
     */
    public function resolveStakeholderRecipientIds(array $candidateIds): array
    {
        if ($candidateIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $candidateIds)
            ->where('status', UserStatusEnum::Active->value)
            ->with(['roles.permissions', 'permissions'])
            ->get()
            ->filter(static fn (User $user): bool => ! $user->trashed()
                && ($user->checkPermissionTo(PermissionEnum::HandoffsView->value)
                    || $user->checkPermissionTo(PermissionEnum::HandoffsReview->value)))
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $recipientIds
     */
    protected function dispatchToRecipients(
        array $recipientIds,
        string $handoffId,
        DealHandoffStatusEnum $status,
        string $dealId,
    ): void {
        foreach ($recipientIds as $recipientUserId) {
            try {
                event(new HandoffStatusChanged(
                    recipientUserId: $recipientUserId,
                    handoffId: $handoffId,
                    status: $status,
                    dealId: $dealId,
                ));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
