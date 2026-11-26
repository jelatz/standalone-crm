<?php

namespace App\Modules\Crm\Deals\Data;

use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Enums\PipelineStageTypeEnum;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DealDetailData extends Data
{
    /**
     * @param  array<string, mixed>  $custom_fields
     */
    public function __construct(
        public string $id,
        public ?string $company_id,
        public ?string $company_name,
        public ?string $company_domain,
        public ?string $primary_contact_id,
        public ?string $primary_contact_name,
        public ?string $primary_contact_email,
        public string $pipeline_id,
        public ?string $pipeline_name,
        public string $stage_id,
        public ?string $stage_name,
        public ?PipelineStageTypeEnum $stage_type,
        public ?int $stage_win_probability,
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
        public ?string $lost_reason,
        public float $board_position,
        public int $version,
        public array $custom_fields,
        public string $created_at,
        public string $updated_at,
        public bool $has_masked_company = false,
        public bool $has_masked_primary_contact = false,
    ) {}

    public static function fromModel(
        Deal $deal,
        bool $canViewCompany = true,
        bool $canViewPrimaryContact = true,
    ): self {
        $hasMaskedCompany = $deal->company_id !== null && ! $canViewCompany;
        $hasMaskedPrimaryContact = $deal->primary_contact_id !== null && ! $canViewPrimaryContact;
        $company = $canViewCompany && $deal->relationLoaded('company') ? $deal->company : null;
        $primaryContact = $canViewPrimaryContact && $deal->relationLoaded('primaryContact') ? $deal->primaryContact : null;
        $pipeline = $deal->relationLoaded('pipeline') ? $deal->pipeline : null;
        $stage = $deal->relationLoaded('stage') ? $deal->stage : null;
        $owner = $deal->relationLoaded('owner') ? $deal->owner : null;
        $accountManager = $deal->relationLoaded('accountManager') ? $deal->accountManager : null;

        return new self(
            id: (string) $deal->id,
            company_id: $canViewCompany ? (string) $deal->company_id : null,
            company_name: $company?->name,
            company_domain: $company?->domain,
            primary_contact_id: $canViewPrimaryContact && $deal->primary_contact_id !== null
                ? (string) $deal->primary_contact_id
                : null,
            primary_contact_name: $primaryContact !== null
                ? ($primaryContact->full_name ?? trim($primaryContact->first_name.' '.$primaryContact->last_name))
                : null,
            primary_contact_email: $primaryContact?->primary_email,
            pipeline_id: (string) $deal->pipeline_id,
            pipeline_name: $pipeline?->name,
            stage_id: (string) $deal->stage_id,
            stage_name: $stage?->name,
            stage_type: $stage?->stage_type,
            stage_win_probability: $stage?->win_probability,
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
            lost_reason: $deal->lost_reason,
            board_position: (float) $deal->board_position,
            version: (int) $deal->version,
            custom_fields: $deal->custom_fields ?? [],
            created_at: $deal->created_at?->toIso8601String() ?? now()->toIso8601String(),
            updated_at: $deal->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            has_masked_company: $hasMaskedCompany,
            has_masked_primary_contact: $hasMaskedPrimaryContact,
        );
    }
}
