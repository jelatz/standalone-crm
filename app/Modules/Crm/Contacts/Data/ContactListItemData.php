<?php

namespace App\Modules\Crm\Contacts\Data;

use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Contacts\Models\ContactEmail;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ContactListItemData extends Data
{
    /**
     * @param  list<string>  $secondary_emails
     * @param  list<mixed>  $phones
     * @param  array<string, mixed>  $custom_fields
     */
    public function __construct(
        public string $id,
        public ?string $company_id,
        public ?string $company_name,
        public string $owner_id,
        public ?string $owner_name,
        public string $first_name,
        public string $last_name,
        public string $full_name,
        public ?string $primary_email,
        public array $secondary_emails,
        public array $phones,
        public ?string $job_title,
        public bool $is_primary_contact,
        public array $custom_fields,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(Contact $contact, bool $canViewCompany = true): self
    {
        $owner = $contact->relationLoaded('owner') ? $contact->owner : null;
        $company = $canViewCompany && $contact->relationLoaded('company') ? $contact->company : null;

        /** @var list<string> $secondaryEmails */
        $secondaryEmails = $contact->relationLoaded('emails')
            ? $contact->emails
                ->filter(static fn (ContactEmail $email): bool => ! $email->is_primary)
                ->pluck('email')
                ->values()
                ->all()
            : [];

        return new self(
            id: (string) $contact->id,
            company_id: $contact->company_id !== null ? (string) $contact->company_id : null,
            company_name: $company?->name,
            owner_id: (string) $contact->owner_id,
            owner_name: $owner?->name,
            first_name: $contact->first_name,
            last_name: $contact->last_name,
            full_name: $contact->full_name ?? trim($contact->first_name.' '.$contact->last_name),
            primary_email: $contact->primary_email,
            secondary_emails: $secondaryEmails,
            phones: array_values($contact->phones ?? []),
            job_title: $contact->job_title,
            is_primary_contact: (bool) $contact->is_primary_contact,
            custom_fields: $contact->custom_fields ?? [],
            created_at: $contact->created_at?->toIso8601String() ?? now()->toIso8601String(),
            updated_at: $contact->updated_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }
}
