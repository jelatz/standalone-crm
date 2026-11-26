<?php

namespace App\Modules\Crm\Deals\Data;

use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RelatedDealData extends Data
{
    public function __construct(
        public string $id,
        public string $company_id,
        public ?string $primary_contact_id,
        public string $pipeline_id,
        public ?string $pipeline_name,
        public string $stage_id,
        public ?string $stage_name,
        public ?PipelineStageTypeEnum $stage_type,
        public string $owner_id,
        public ?string $owner_name,
        public ?string $account_manager_id,
        public ?string $account_manager_name,
        public string $title,
        public int $amount_cents,
        public string $currency,
        public ?string $expected_close_date,
        public ?string $won_at,
        public ?string $lost_at,
        public string $updated_at,
    ) {}

    public static function fromModel(Deal $deal): self
    {
        $pipeline = $deal->relationLoaded('pipeline') ? $deal->pipeline : null;
        $stage = $deal->relationLoaded('stage') ? $deal->stage : null;
        $owner = $deal->relationLoaded('owner') ? $deal->owner : null;
        $accountManager = $deal->relationLoaded('accountManager') ? $deal->accountManager : null;

        return new self(
            id: (string) $deal->id,
            company_id: (string) $deal->company_id,
            primary_contact_id: $deal->primary_contact_id !== null ? (string) $deal->primary_contact_id : null,
            pipeline_id: (string) $deal->pipeline_id,
            pipeline_name: $pipeline?->name,
            stage_id: (string) $deal->stage_id,
            stage_name: $stage?->name,
            stage_type: $stage?->stage_type,
            owner_id: (string) $deal->owner_id,
            owner_name: $owner?->name,
            account_manager_id: $deal->account_manager_id !== null ? (string) $deal->account_manager_id : null,
            account_manager_name: $accountManager?->name,
            title: $deal->title,
            amount_cents: $deal->amount_cents,
            currency: $deal->currency,
            expected_close_date: $deal->expected_close_date?->format('Y-m-d'),
            won_at: $deal->won_at?->toIso8601String(),
            lost_at: $deal->lost_at?->toIso8601String(),
            updated_at: $deal->updated_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }
}
