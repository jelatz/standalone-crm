<?php

namespace App\Modules\Projects\Projects\Data;

use App\Modules\Projects\Projects\Enums\ProjectHealthEnum;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use App\Modules\Projects\Projects\Models\Project;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProjectSummaryData extends Data
{
    public function __construct(
        public string $id,
        public string $code,
        public ?string $company_id,
        public ?string $company_name,
        public ?string $primary_deal_id,
        public ?string $primary_deal_title,
        public ?string $deal_handoff_id,
        public string $owner_id,
        public ?string $owner_name,
        public ?string $account_manager_id,
        public ?string $account_manager_name,
        public string $department_id,
        public ?string $department_name,
        public string $name,
        public ProjectStatusEnum $status,
        public ProjectHealthEnum $health,
        public ?string $start_date,
        public ?string $target_end_date,
        public ?string $completed_at,
        public int $tasks_count = 0,
        public int $completed_tasks_count = 0,
        public bool $has_masked_company = false,
        public bool $has_masked_deal = false,
        public string $created_at = '',
        public string $updated_at = '',
    ) {}

    public static function fromModel(
        Project $project,
        bool $canViewCompany = true,
        bool $canViewDeal = true,
    ): self {
        $hasMaskedCompany = $project->company_id !== null && ! $canViewCompany;
        $hasMaskedDeal = $project->primary_deal_id !== null && ! $canViewDeal;

        $company = $canViewCompany && $project->relationLoaded('company') ? $project->company : null;
        $primaryDeal = $canViewDeal && $project->relationLoaded('primaryDeal') ? $project->primaryDeal : null;
        $owner = $project->relationLoaded('owner') ? $project->owner : null;
        $accountManager = $project->relationLoaded('accountManager') ? $project->accountManager : null;
        $department = $project->relationLoaded('department') ? $project->department : null;

        return new self(
            id: (string) $project->id,
            code: $project->code,
            company_id: $canViewCompany && $project->company_id !== null ? (string) $project->company_id : null,
            company_name: $company?->name,
            primary_deal_id: $canViewDeal && $project->primary_deal_id !== null ? (string) $project->primary_deal_id : null,
            primary_deal_title: $primaryDeal?->title,
            deal_handoff_id: $project->deal_handoff_id !== null ? (string) $project->deal_handoff_id : null,
            owner_id: (string) $project->owner_id,
            owner_name: $owner?->name,
            account_manager_id: $project->account_manager_id !== null ? (string) $project->account_manager_id : null,
            account_manager_name: $accountManager?->name,
            department_id: (string) $project->department_id,
            department_name: $department?->name,
            name: $project->name,
            status: $project->status,
            health: $project->health,
            start_date: $project->start_date?->format('Y-m-d'),
            target_end_date: $project->target_end_date?->format('Y-m-d'),
            completed_at: $project->completed_at?->toIso8601String(),
            tasks_count: (int) ($project->tasks_count ?? 0),
            completed_tasks_count: (int) ($project->completed_tasks_count ?? 0),
            has_masked_company: $hasMaskedCompany,
            has_masked_deal: $hasMaskedDeal,
            created_at: $project->created_at?->toIso8601String() ?? now()->toIso8601String(),
            updated_at: $project->updated_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }
}
