<?php

namespace App\Modules\Crm\Contacts\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Data\UpsertContactData;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Contacts\Models\ContactEmail;
use App\Modules\Crm\CustomFields\Actions\ValidateEntityCustomFieldsAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

class UpsertContactAction
{
    public function __construct(
        protected ValidateEntityCustomFieldsAction $validateCustomFields,
        protected RecordActivityAction $recordActivity,
    ) {}

    public function execute(User $actor, UpsertContactData $data, ?Contact $contact = null): Contact
    {
        $isCreate = $contact === null;

        if ($isCreate) {
            Gate::forUser($actor)->authorize('create', Contact::class);
        } else {
            Gate::forUser($actor)->authorize('update', $contact);
        }

        if (! $data->company_id instanceof Optional) {
            $targetCompanyId = $data->company_id !== null && trim($data->company_id) !== ''
                ? trim($data->company_id)
                : null;

            if ($targetCompanyId !== null && ($isCreate || $targetCompanyId !== $contact->company_id)) {
                $companyVisible = Company::query()
                    ->visibleTo($actor)
                    ->whereKey($targetCompanyId)
                    ->exists();

                if (! $companyVisible) {
                    throw new AuthorizationException('You are not authorized to link a contact to the selected company.');
                }
            }
        } else {
            $targetCompanyId = $contact?->company_id;
        }

        $firstName = ! $data->first_name instanceof Optional
            ? trim($data->first_name)
            : (string) ($contact?->first_name ?? '');

        $lastName = ! $data->last_name instanceof Optional
            ? trim($data->last_name)
            : (string) ($contact?->last_name ?? '');

        if ($firstName === '' || $lastName === '') {
            throw ValidationException::withMessages([
                'first_name' => ['First name and last name are required.'],
            ]);
        }

        $primaryEmail = ! $data->primary_email instanceof Optional
            ? ($data->primary_email !== null && trim($data->primary_email) !== '' ? trim($data->primary_email) : null)
            : $contact?->primary_email;

        if (! $data->secondary_emails instanceof Optional) {
            $rawSecondaryEmails = $data->secondary_emails ?? [];
        } elseif ($contact !== null) {
            $rawSecondaryEmails = $contact->emails()
                ->where('is_primary', false)
                ->pluck('email')
                ->all();
        } else {
            $rawSecondaryEmails = [];
        }

        $phones = ! $data->phones instanceof Optional
            ? array_values($data->phones ?? [])
            : ($contact?->phones ?? []);

        $jobTitle = ! $data->job_title instanceof Optional
            ? ($data->job_title !== null && trim($data->job_title) !== '' ? trim($data->job_title) : null)
            : $contact?->job_title;

        $isPrimaryContact = ! $data->is_primary_contact instanceof Optional
            ? (bool) $data->is_primary_contact
            : (bool) ($contact?->is_primary_contact ?? false);

        if ($isCreate) {
            $rawCustomFields = ! $data->custom_fields instanceof Optional ? $data->custom_fields : [];
            $customFields = $this->validateCustomFields->execute(
                entityType: CustomFieldEntityTypeEnum::Contact,
                values: $rawCustomFields,
                pipelineId: null,
                partial: false,
            );
        } elseif (! $data->custom_fields instanceof Optional) {
            $validatedPartial = $this->validateCustomFields->execute(
                entityType: CustomFieldEntityTypeEnum::Contact,
                values: $data->custom_fields,
                pipelineId: null,
                partial: true,
            );

            $merged = array_merge($contact->custom_fields ?? [], $validatedPartial);
            $customFields = array_filter($merged, static fn (mixed $v): bool => $v !== null);
        } else {
            $customFields = $contact->custom_fields ?? [];
        }

        $changedFields = [];
        if ($contact !== null) {
            if ($targetCompanyId !== $contact->company_id) {
                $changedFields[] = 'company_id';
            }
            if ($firstName !== $contact->first_name) {
                $changedFields[] = 'first_name';
            }
            if ($lastName !== $contact->last_name) {
                $changedFields[] = 'last_name';
            }
            if ($primaryEmail !== $contact->primary_email) {
                $changedFields[] = 'primary_email';
            }
            if (! $data->secondary_emails instanceof Optional) {
                $changedFields[] = 'secondary_emails';
            }
            if ($phones !== ($contact->phones ?? [])) {
                $changedFields[] = 'phones';
            }
            if ($jobTitle !== $contact->job_title) {
                $changedFields[] = 'job_title';
            }
            if ($isPrimaryContact !== (bool) $contact->is_primary_contact) {
                $changedFields[] = 'is_primary_contact';
            }
            if ($customFields !== ($contact->custom_fields ?? [])) {
                $changedFields[] = 'custom_fields';
            }
        }

        return DB::transaction(function () use (
            $actor,
            $contact,
            $isCreate,
            $targetCompanyId,
            $firstName,
            $lastName,
            $primaryEmail,
            $rawSecondaryEmails,
            $phones,
            $jobTitle,
            $isPrimaryContact,
            $customFields,
            $changedFields,
        ): Contact {
            $attributes = [
                'company_id' => $targetCompanyId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'primary_email' => $primaryEmail,
                'phones' => $phones,
                'job_title' => $jobTitle,
                'is_primary_contact' => $isPrimaryContact,
                'custom_fields' => $customFields,
            ];

            if ($isCreate) {
                $attributes['owner_id'] = $actor->id;
                $persisted = Contact::query()->create($attributes);
            } else {
                $contact->update($attributes);
                $persisted = $contact;
            }

            $this->syncEmails($persisted, $primaryEmail, $rawSecondaryEmails);

            if (! $isCreate && $changedFields !== []) {
                $targets = ['contact' => (string) $persisted->id];
                if ($persisted->company_id !== null) {
                    $targets['company'] = (string) $persisted->company_id;
                }

                $this->recordActivity->execute(
                    actor: $actor,
                    activityType: ActivityTypeEnum::FieldUpdated,
                    sourceType: $persisted->getMorphClass(),
                    sourceId: (string) $persisted->id,
                    targets: $targets,
                    summary: 'Updated contact fields: '.implode(', ', array_values(array_unique($changedFields))),
                    metadata: ['changed_fields' => array_values(array_unique($changedFields))],
                );
            }

            return $persisted->refresh()->load(['owner:id,name', 'company:id,name,domain', 'emails']);
        });
    }

    /**
     * Atomically sync primary and secondary emails into contact_emails with case-insensitive dedupe.
     *
     * @param  array<mixed, mixed>  $secondaryEmails
     */
    protected function syncEmails(Contact $contact, ?string $primaryEmail, array $secondaryEmails): void
    {
        $seenLower = [];
        /** @var list<array{email: string, is_primary: bool}> $rows */
        $rows = [];

        if ($primaryEmail !== null) {
            $trimmedPrimary = trim($primaryEmail);
            if ($trimmedPrimary !== '') {
                $seenLower[mb_strtolower($trimmedPrimary)] = true;
                $rows[] = [
                    'email' => $trimmedPrimary,
                    'is_primary' => true,
                ];
            }
        }

        foreach ($secondaryEmails as $rawEmail) {
            if (! is_string($rawEmail)) {
                continue;
            }

            $trimmed = trim($rawEmail);
            if ($trimmed === '') {
                continue;
            }

            $lower = mb_strtolower($trimmed);
            if (isset($seenLower[$lower])) {
                continue;
            }

            $seenLower[$lower] = true;
            $rows[] = [
                'email' => $trimmed,
                'is_primary' => false,
            ];
        }

        ContactEmail::query()
            ->where('contact_id', $contact->id)
            ->delete();

        foreach ($rows as $row) {
            ContactEmail::query()->create([
                'contact_id' => $contact->id,
                'email' => $row['email'],
                'is_primary' => $row['is_primary'],
            ]);
        }
    }
}
