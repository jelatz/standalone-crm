<?php

namespace App\Modules\Projects\Handoffs\Data;

use App\Modules\Core\Users\Data\UserSummaryData;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Templates\Data\ProjectTemplateSummaryData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DealHandoffSummaryData extends Data
{
    public function __construct(
        public string $id,
        public string $deal_id,
        public string $company_id,
        public ?string $deal_title,
        public ?int $deal_amount_cents,
        public ?string $deal_currency,
        public ?string $company_name,
        public DealHandoffStatusEnum $status,
        public ?string $target_kickoff_date,
        public ?string $review_feedback,
        public ?UserSummaryData $submitted_by,
        public ?UserSummaryData $reviewed_by,
        public ?UserSummaryData $assigned_pm,
        public ?UserSummaryData $assigned_am,
        public ?ProjectTemplateSummaryData $template,
        public ?HandoffProjectSummaryData $provisioned_project,
        public string $submitted_at,
        public ?string $reviewed_at,
        public ?string $provisioned_at,
    ) {}

    public static function fromModel(DealHandoff $handoff): self
    {
        $deal = $handoff->relationLoaded('deal') ? $handoff->deal : null;
        $company = $handoff->relationLoaded('company') ? $handoff->company : null;
        $submittedBy = $handoff->relationLoaded('submittedBy') && $handoff->submittedBy !== null
            ? UserSummaryData::fromModel($handoff->submittedBy)
            : null;
        $reviewedBy = $handoff->relationLoaded('reviewedBy') && $handoff->reviewedBy !== null
            ? UserSummaryData::fromModel($handoff->reviewedBy)
            : null;
        $assignedPm = $handoff->relationLoaded('assignedPm') && $handoff->assignedPm !== null
            ? UserSummaryData::fromModel($handoff->assignedPm)
            : null;
        $assignedAm = $handoff->relationLoaded('assignedAm') && $handoff->assignedAm !== null
            ? UserSummaryData::fromModel($handoff->assignedAm)
            : null;
        $template = $handoff->relationLoaded('template') && $handoff->template !== null
            ? ProjectTemplateSummaryData::fromModel($handoff->template)
            : null;
        $project = $handoff->relationLoaded('project') && $handoff->project !== null
            ? HandoffProjectSummaryData::fromModel($handoff->project)
            : null;

        return new self(
            id: (string) $handoff->id,
            deal_id: (string) $handoff->deal_id,
            company_id: (string) $handoff->company_id,
            deal_title: $deal?->title,
            deal_amount_cents: $deal !== null ? (int) $deal->amount_cents : null,
            deal_currency: $deal?->currency,
            company_name: $company?->name,
            status: $handoff->status,
            target_kickoff_date: $handoff->target_kickoff_date?->format('Y-m-d'),
            review_feedback: $handoff->review_feedback,
            submitted_by: $submittedBy,
            reviewed_by: $reviewedBy,
            assigned_pm: $assignedPm,
            assigned_am: $assignedAm,
            template: $template,
            provisioned_project: $project,
            submitted_at: $handoff->submitted_at->toIso8601String(),
            reviewed_at: $handoff->reviewed_at?->toIso8601String(),
            provisioned_at: $handoff->provisioned_at?->toIso8601String(),
        );
    }
}
