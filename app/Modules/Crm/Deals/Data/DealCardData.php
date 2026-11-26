<?php

namespace App\Modules\Crm\Deals\Data;

use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DealCardData extends Data
{
    /**
     * @param  array<string, mixed>  $custom_fields
     */
    public function __construct(
        public string $id,
        public ?string $company_id,
        public ?string $company_name,
        public ?string $primary_contact_id,
        public ?string $primary_contact_name,
        public string $pipeline_id,
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
        public string $stage_entered_at,
        public ?string $won_at,
        public ?string $lost_at,
        public float $board_position,
        public int $version,
        public bool $is_rotting,
        public array $custom_fields,
        public string $updated_at,
        public ?int $win_probability = null,
        public int $effective_win_probability = 0,
    ) {}

    public static function fromModel(
        Deal $deal,
        bool $canViewCompany = true,
        bool $canViewPrimaryContact = true,
    ): self {
        $company = $canViewCompany && $deal->relationLoaded('company') ? $deal->company : null;
        $primaryContact = $canViewPrimaryContact && $deal->relationLoaded('primaryContact') ? $deal->primaryContact : null;
        $stage = $deal->relationLoaded('stage') ? $deal->stage : null;
        $owner = $deal->relationLoaded('owner') ? $deal->owner : null;
        $accountManager = $deal->relationLoaded('accountManager') ? $deal->accountManager : null;

        $isRotting = $stage !== null
            && $stage->stage_type === PipelineStageTypeEnum::Open
            && $stage->rotting_days !== null
            && $stage->rotting_days > 0
            && $deal->stage_entered_at !== null
            && $deal->stage_entered_at->addDays($stage->rotting_days)->isPast();

        $effectiveWinProbability = $deal->win_probability !== null
            ? (int) $deal->win_probability
            : ($stage?->win_probability ?? 0);

        return new self(
            id: (string) $deal->id,
            company_id: $canViewCompany ? (string) $deal->company_id : null,
            company_name: $company?->name,
            primary_contact_id: $canViewPrimaryContact && $deal->primary_contact_id !== null
                ? (string) $deal->primary_contact_id
                : null,
            primary_contact_name: $primaryContact !== null
                ? ($primaryContact->full_name ?? trim($primaryContact->first_name.' '.$primaryContact->last_name))
                : null,
            pipeline_id: (string) $deal->pipeline_id,
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
            stage_entered_at: $deal->stage_entered_at?->toIso8601String() ?? now()->toIso8601String(),
            won_at: $deal->won_at?->toIso8601String(),
            lost_at: $deal->lost_at?->toIso8601String(),
            board_position: (float) $deal->board_position,
            version: (int) $deal->version,
            is_rotting: $isRotting,
            custom_fields: $deal->custom_fields ?? [],
            updated_at: $deal->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            win_probability: $deal->win_probability !== null ? (int) $deal->win_probability : null,
            effective_win_probability: $effectiveWinProbability,
        );
    }
}
