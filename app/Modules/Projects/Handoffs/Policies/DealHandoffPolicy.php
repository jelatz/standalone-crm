<?php

namespace App\Modules\Projects\Handoffs\Policies;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Support\Silo\HandlesSiloPolicy;
use App\Support\Silo\VisibleScopeResolver;
use Illuminate\Support\Facades\Gate;

class DealHandoffPolicy
{
    use HandlesSiloPolicy;

    public function viewAny(User $user): bool
    {
        return $this->isActiveEmployee($user)
            && (
                $user->checkPermissionTo(PermissionEnum::HandoffsView->value)
                || $user->checkPermissionTo(PermissionEnum::HandoffsReview->value)
            );
    }

    public function view(User $user, DealHandoff $handoff): bool
    {
        if (! $handoff->exists || ! $this->isActiveEmployee($user)) {
            return false;
        }

        if (
            ! $user->checkPermissionTo(PermissionEnum::HandoffsView->value)
            && ! $user->checkPermissionTo(PermissionEnum::HandoffsReview->value)
        ) {
            return false;
        }

        if (app(VisibleScopeResolver::class)->for($user)->isUnrestrictedAdmin) {
            return true;
        }

        if ($user->checkPermissionTo(PermissionEnum::HandoffsReview->value)) {
            return true;
        }

        if (
            (string) $handoff->submitted_by_id === (string) $user->id
            || ($handoff->assigned_pm_id !== null && (string) $handoff->assigned_pm_id === (string) $user->id)
            || ($handoff->assigned_am_id !== null && (string) $handoff->assigned_am_id === (string) $user->id)
        ) {
            return true;
        }

        $deal = $this->resolveDeal($handoff);

        return $deal !== null && Gate::forUser($user)->allows('view', $deal);
    }

    public function submit(User $user, ?Deal $deal = null): bool
    {
        if (! $this->isActiveEmployee($user) || ! $user->checkPermissionTo(PermissionEnum::HandoffsSubmit->value)) {
            return false;
        }

        if ($deal === null) {
            return true;
        }

        return $deal->exists
            && ! $deal->trashed()
            && Gate::forUser($user)->allows('update', $deal);
    }

    public function resubmit(User $user, DealHandoff $handoff): bool
    {
        if (
            ! $handoff->exists
            || ! $this->isActiveEmployee($user)
            || ! $user->checkPermissionTo(PermissionEnum::HandoffsSubmit->value)
        ) {
            return false;
        }

        $deal = $this->resolveDeal($handoff);

        if ($deal === null || $deal->trashed()) {
            return false;
        }

        if (app(VisibleScopeResolver::class)->for($user)->isUnrestrictedAdmin) {
            return true;
        }

        if ((string) $handoff->submitted_by_id === (string) $user->id) {
            return true;
        }

        return Gate::forUser($user)->allows('update', $deal);
    }

    public function requestChanges(User $user, DealHandoff $handoff): bool
    {
        if (! $handoff->exists || ! $this->isActiveEmployee($user)) {
            return false;
        }

        return $user->checkPermissionTo(PermissionEnum::HandoffsReview->value)
            || $user->checkPermissionTo(PermissionEnum::HandoffsRequestInfo->value);
    }

    public function reject(User $user, DealHandoff $handoff): bool
    {
        return $handoff->exists
            && $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::HandoffsReview->value);
    }

    public function approve(User $user, DealHandoff $handoff): bool
    {
        return $handoff->exists
            && $this->isActiveEmployee($user)
            && $user->checkPermissionTo(PermissionEnum::HandoffsApprove->value);
    }

    protected function resolveDeal(DealHandoff $handoff): ?Deal
    {
        if ($handoff->relationLoaded('deal')) {
            return $handoff->getRelation('deal');
        }

        return Deal::query()->find($handoff->deal_id);
    }
}
