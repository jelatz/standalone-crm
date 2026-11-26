<?php

namespace App\Modules\Projects\Handoffs\Support;

use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Users\Data\UserSummaryData;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Templates\Actions\ListProjectTemplatesAction;
use App\Modules\Projects\Templates\Data\ProjectTemplateSummaryData;
use App\Modules\Projects\Templates\Models\ProjectTemplate;
use Illuminate\Support\Facades\Gate;

final class DealHandoffQuerySupport
{
    /**
     * @var list<string>
     */
    public const array USER_SUMMARY_COLUMNS = [
        'id',
        'employee_code',
        'name',
        'email',
        'job_title',
        'department_id',
        'status',
        'created_at',
    ];

    public function __construct(
        private readonly ListProjectTemplatesAction $listTemplates,
    ) {}

    /**
     * @return array<int|string, mixed>
     */
    public static function eagerLoadRelations(): array
    {
        $userRelation = static fn ($query) => $query
            ->withTrashed()
            ->select(self::USER_SUMMARY_COLUMNS)
            ->with(['department:id,name', 'roles:id,name']);

        return [
            'deal' => static fn ($query) => $query
                ->withTrashed()
                ->select(['id', 'company_id', 'stage_id', 'title', 'amount_cents', 'currency'])
                ->with('stage:id,name,stage_type'),
            'company' => static fn ($query) => $query
                ->withTrashed()
                ->select(['id', 'name', 'domain', 'lifecycle_stage']),
            'submittedBy' => $userRelation,
            'reviewedBy' => $userRelation,
            'assignedPm' => $userRelation,
            'assignedAm' => $userRelation,
            'template' => static fn ($query) => $query
                ->with('department:id,name')
                ->withCount('tasks'),
            'project' => static fn ($query) => $query
                ->withTrashed()
                ->select(['id', 'deal_handoff_id', 'code', 'name', 'status', 'health']),
        ];
    }

    public function loadFreshHandoff(DealHandoff $handoff): DealHandoff
    {
        return DealHandoff::query()
            ->with(self::eagerLoadRelations())
            ->findOrFail($handoff->id);
    }

    /**
     * @return list<ProjectTemplateSummaryData>
     */
    public function templatesForViewer(User $viewer): array
    {
        if (! Gate::forUser($viewer)->allows('viewAny', ProjectTemplate::class)) {
            return [];
        }

        return $this->listTemplates->execute($viewer, activeOnly: true);
    }

    /**
     * @return list<UserSummaryData>
     */
    public function assignablePmsForViewer(User $viewer): array
    {
        if (! $viewer->checkPermissionTo(PermissionEnum::HandoffsApprove->value)) {
            return [];
        }

        return User::query()
            ->with(['department:id,name', 'roles:id,name'])
            ->where('status', UserStatusEnum::Active->value)
            ->permission(PermissionEnum::ProjectsUpdate->value)
            ->orderBy('name')
            ->get(self::USER_SUMMARY_COLUMNS)
            ->map(static fn (User $user): UserSummaryData => UserSummaryData::fromModel($user))
            ->values()
            ->all();
    }

    /**
     * @return list<UserSummaryData>
     */
    public function assignableAmsForViewer(User $viewer): array
    {
        if (
            ! $viewer->checkPermissionTo(PermissionEnum::HandoffsApprove->value)
            && ! $viewer->checkPermissionTo(PermissionEnum::HandoffsSubmit->value)
        ) {
            return [];
        }

        return User::query()
            ->with(['department:id,name', 'roles:id,name'])
            ->where('status', UserStatusEnum::Active->value)
            ->orderBy('name')
            ->get(self::USER_SUMMARY_COLUMNS)
            ->map(static fn (User $user): UserSummaryData => UserSummaryData::fromModel($user))
            ->values()
            ->all();
    }
}
