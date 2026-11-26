<?php

namespace App\Modules\Crm\Leads\Data;

use App\Modules\Crm\Leads\Enums\LeadSourceEnum;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Leads\Models\Lead;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class LeadListItemData extends Data
{
    /**
     * @param  array<string, mixed>  $custom_fields
     */
    public function __construct(
        public string $id,
        public string $owner_id,
        public ?string $owner_name,
        public string $first_name,
        public string $last_name,
        public string $full_name,
        public ?string $email,
        public ?string $phone,
        public ?string $job_title,
        public ?string $company_name,
        public ?string $website,
        public LeadStatusEnum $status,
        public LeadSourceEnum $source,
        public ?int $estimated_value_cents,
        public string $currency,
        public ?string $converted_at,
        public ?string $converted_company_id,
        public ?string $converted_contact_id,
        public ?string $converted_deal_id,
        public array $custom_fields,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(
        Lead $lead,
        bool $canViewConvertedCompany = true,
        bool $canViewConvertedContact = true,
        bool $canViewConvertedDeal = true,
    ): self {
        $owner = $lead->relationLoaded('owner') ? $lead->owner : null;
        $fullName = $lead->full_name ?? trim($lead->first_name.' '.$lead->last_name);

        return new self(
            id: (string) $lead->id,
            owner_id: (string) $lead->owner_id,
            owner_name: $owner?->name,
            first_name: $lead->first_name,
            last_name: $lead->last_name,
            full_name: $fullName,
            email: $lead->email,
            phone: $lead->phone,
            job_title: $lead->job_title,
            company_name: $lead->company_name,
            website: $lead->website,
            status: $lead->status,
            source: $lead->source,
            estimated_value_cents: $lead->estimated_value_cents,
            currency: $lead->currency,
            converted_at: $lead->converted_at?->toIso8601String(),
            converted_company_id: $canViewConvertedCompany && $lead->converted_company_id !== null
                ? (string) $lead->converted_company_id
                : null,
            converted_contact_id: $canViewConvertedContact && $lead->converted_contact_id !== null
                ? (string) $lead->converted_contact_id
                : null,
            converted_deal_id: $canViewConvertedDeal && $lead->converted_deal_id !== null
                ? (string) $lead->converted_deal_id
                : null,
            custom_fields: $lead->custom_fields ?? [],
            created_at: $lead->created_at?->toIso8601String() ?? now()->toIso8601String(),
            updated_at: $lead->updated_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }
}
