<?php

namespace App\Modules\Projects\Handoffs\Data;

use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class HandoffDealSummaryData extends Data
{
    public function __construct(
        public string $id,
        public string $title,
        public int $amount_cents,
        public string $currency,
        public string $stage_id,
        public ?string $stage_name,
        public ?PipelineStageTypeEnum $stage_type,
    ) {}

    public static function fromModel(Deal $deal): self
    {
        $stage = $deal->relationLoaded('stage') ? $deal->stage : null;

        return new self(
            id: (string) $deal->id,
            title: $deal->title,
            amount_cents: (int) $deal->amount_cents,
            currency: $deal->currency,
            stage_id: (string) $deal->stage_id,
            stage_name: $stage?->name,
            stage_type: $stage?->stage_type,
        );
    }
}
