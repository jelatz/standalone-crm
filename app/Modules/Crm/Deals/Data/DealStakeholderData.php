<?php

namespace App\Modules\Crm\Deals\Data;

use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Deals\Enums\DealStakeholderRoleEnum;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DealStakeholderData extends Data
{
    public function __construct(
        public string $contact_id,
        public string $full_name,
        public ?string $primary_email,
        public ?string $job_title,
        public DealStakeholderRoleEnum $stakeholder_role,
    ) {}

    public static function fromContact(Contact $contact): self
    {
        $rawRole = $contact->getRelationValue('pivot')?->stakeholder_role;
        $role = $rawRole instanceof DealStakeholderRoleEnum
            ? $rawRole
            : DealStakeholderRoleEnum::from((string) $rawRole);

        return new self(
            contact_id: (string) $contact->id,
            full_name: $contact->full_name ?? trim($contact->first_name.' '.$contact->last_name),
            primary_email: $contact->primary_email,
            job_title: $contact->job_title,
            stakeholder_role: $role,
        );
    }
}
