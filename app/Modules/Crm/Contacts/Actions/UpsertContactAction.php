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
        if ($contact === null) {
            Gate::forUser($actor)->authorize('create', Contact::class);

            return $this->createContact($actor, $data);
        }

        Gate::forUser($actor)->authorize('update', $contact);

        return $this->updateContact($actor, $contact, $data);
    }

    protected function createContact(User $actor, UpsertContactData $data): Contact
    {
        $targetCompanyId = ! $data->company_id instanceof Optional && $data->company_id !== null && trim($data->company_id) !== ''
            ? trim($data->company_id)
            : null;

        $firstName = ! $data->first_name instanceof Optional ? trim($data->first_name) : '';
        $lastName = ! $data->last_name instanceof Optional ? trim($data->last_name) : '';

        if ($firstName === '' || $lastName === '') {
            throw ValidationException::withMessages([
                'first_name' => ['First name and last name are required.'],
            ]);
        }

        $primaryEmail = ! $data->primary_email instanceof Optional && $data->primary_email !== null && trim($data->primary_email) !== ''
            ? trim($data->primary_email)
            : null;

        $rawSecondaryEmails = ! $data->secondary_emails instanceof Optional
            ? ($data->secondary_emails ?? [])
            : [];

        $phones = ! $data->phones instanceof Optional ? array_values($data->phones ?? []) : [];
        $jobTitle = ! $data->job_title instanceof Optional && $data->job_title !== null && trim($data->job_title) !== ''
            ? trim($data->job_title)
            : null;
        $isPrimaryContact = ! $data->is_primary_contact instanceof Optional
            ? (bool) $data->is_primary_contact
            : false;

        $rawCustomFields = ! $data->custom_fields instanceof Optional ? $data->custom_fields : [];
        $customFields = $this->validateCustomFields->execute(
            entityType: CustomFieldEntityTypeEnum::Contact,
            values: $rawCustomFields,
            pipelineId: null,
            partial: false,
        );

        $normalizedEmails = $this->normalizeEmails($primaryEmail, $rawSecondaryEmails);

        return DB::transaction(function () use (
            $actor,
            $targetCompanyId,
            $firstName,
            $lastName,
            $primaryEmail,
            $phones,
            $jobTitle,
            $isPrimaryContact,
            $customFields,
            $normalizedEmails,
        ): Contact {
            if ($targetCompanyId !== null) {
                $this->assertCompanyVisible($actor, $targetCompanyId);
            }

            $created = Contact::query()->create([
                'company_id' => $targetCompanyId,
                'owner_id' => $actor->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'primary_email' => $primaryEmail,
                'phones' => $phones,
                'job_title' => $jobTitle,
                'is_primary_contact' => $isPrimaryContact,
                'custom_fields' => $customFields,
            ]);

            $this->replaceContactEmails($created, $normalizedEmails['rows']);

            return $created->refresh()->load(['owner:id,name', 'company:id,name,domain', 'emails']);
        });
    }

    protected function updateContact(User $actor, Contact $contact, UpsertContactData $data): Contact
    {
        if (! $data->first_name instanceof Optional && trim($data->first_name) === '') {
            throw ValidationException::withMessages([
                'first_name' => ['First name cannot be empty.'],
            ]);
        }

        if (! $data->last_name instanceof Optional && trim($data->last_name) === '') {
            throw ValidationException::withMessages([
                'last_name' => ['Last name cannot be empty.'],
            ]);
        }

        $validatedPartialCustomFields = ! $data->custom_fields instanceof Optional
            ? $this->validateCustomFields->execute(
                entityType: CustomFieldEntityTypeEnum::Contact,
                values: $data->custom_fields,
                pipelineId: null,
                partial: true,
            )
            : null;

        return DB::transaction(function () use ($actor, $contact, $data, $validatedPartialCustomFields): Contact {
            $lockedContact = Contact::query()
                ->whereKey($contact->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $data->company_id instanceof Optional) {
                $targetCompanyId = $data->company_id !== null && trim($data->company_id) !== ''
                    ? trim($data->company_id)
                    : null;

                if (
                    $lockedContact->company_id !== null
                    && $targetCompanyId !== (string) $lockedContact->company_id
                ) {
                    $existingCompany = Company::query()->find($lockedContact->company_id);
                    if ($existingCompany === null || ! Gate::forUser($actor)->allows('view', $existingCompany)) {
                        throw new AuthorizationException('You are not authorized to unlink or replace an inaccessible company on this contact.');
                    }
                }

                if ($targetCompanyId !== null) {
                    $this->assertCompanyVisible($actor, $targetCompanyId);
                }
            } else {
                $targetCompanyId = $lockedContact->company_id;
            }

            $firstName = ! $data->first_name instanceof Optional
                ? trim($data->first_name)
                : $lockedContact->first_name;

            $lastName = ! $data->last_name instanceof Optional
                ? trim($data->last_name)
                : $lockedContact->last_name;

            $primaryEmail = ! $data->primary_email instanceof Optional
                ? ($data->primary_email !== null && trim($data->primary_email) !== '' ? trim($data->primary_email) : null)
                : $lockedContact->primary_email;

            $existingSecondaryEmails = ContactEmail::query()
                ->where('contact_id', $lockedContact->id)
                ->where('is_primary', false)
                ->pluck('email')
                ->all();

            $rawSecondaryEmails = ! $data->secondary_emails instanceof Optional
                ? ($data->secondary_emails ?? [])
                : $existingSecondaryEmails;

            $existingNormalized = $this->normalizeEmails($lockedContact->primary_email, $existingSecondaryEmails);
            $nextNormalized = $this->normalizeEmails($primaryEmail, $rawSecondaryEmails);

            $phones = ! $data->phones instanceof Optional
                ? array_values($data->phones ?? [])
                : ($lockedContact->phones ?? []);

            $jobTitle = ! $data->job_title instanceof Optional
                ? ($data->job_title !== null && trim($data->job_title) !== '' ? trim($data->job_title) : null)
                : $lockedContact->job_title;

            $isPrimaryContact = ! $data->is_primary_contact instanceof Optional
                ? (bool) $data->is_primary_contact
                : (bool) $lockedContact->is_primary_contact;

            if ($validatedPartialCustomFields !== null) {
                $merged = array_merge($lockedContact->custom_fields ?? [], $validatedPartialCustomFields);
                $customFields = array_filter($merged, static fn (mixed $v): bool => $v !== null);
            } else {
                $customFields = $lockedContact->custom_fields ?? [];
            }

            $changedFields = [];
            if ($targetCompanyId !== $lockedContact->company_id) {
                $changedFields[] = 'company_id';
            }
            if ($firstName !== $lockedContact->first_name) {
                $changedFields[] = 'first_name';
            }
            if ($lastName !== $lockedContact->last_name) {
                $changedFields[] = 'last_name';
            }
            if ($primaryEmail !== $lockedContact->primary_email) {
                $changedFields[] = 'primary_email';
            }
            if (
                ! $data->secondary_emails instanceof Optional
                && $nextNormalized['secondary_lower'] !== $existingNormalized['secondary_lower']
            ) {
                $changedFields[] = 'secondary_emails';
            }
            if ($phones !== ($lockedContact->phones ?? [])) {
                $changedFields[] = 'phones';
            }
            if ($jobTitle !== $lockedContact->job_title) {
                $changedFields[] = 'job_title';
            }
            if ($isPrimaryContact !== (bool) $lockedContact->is_primary_contact) {
                $changedFields[] = 'is_primary_contact';
            }
            if ($customFields !== ($lockedContact->custom_fields ?? [])) {
                $changedFields[] = 'custom_fields';
            }

            $lockedContact->update([
                'company_id' => $targetCompanyId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'primary_email' => $primaryEmail,
                'phones' => $phones,
                'job_title' => $jobTitle,
                'is_primary_contact' => $isPrimaryContact,
                'custom_fields' => $customFields,
            ]);

            $this->replaceContactEmails($lockedContact, $nextNormalized['rows']);

            if ($changedFields !== []) {
                $targets = ['contact' => (string) $lockedContact->id];
                if ($lockedContact->company_id !== null) {
                    $targets['company'] = (string) $lockedContact->company_id;
                }

                $this->recordActivity->execute(
                    actor: $actor,
                    activityType: ActivityTypeEnum::FieldUpdated,
                    sourceType: $lockedContact->getMorphClass(),
                    sourceId: (string) $lockedContact->id,
                    targets: $targets,
                    summary: 'Updated contact fields: '.implode(', ', $changedFields),
                    metadata: ['changed_fields' => $changedFields],
                );
            }

            return $lockedContact->refresh()->load(['owner:id,name', 'company:id,name,domain', 'emails']);
        });
    }

    protected function assertCompanyVisible(User $actor, string $companyId): void
    {
        $companyVisible = Gate::forUser($actor)->allows('viewAny', Company::class)
            && Company::query()
                ->visibleTo($actor)
                ->whereKey($companyId)
                ->exists();

        if (! $companyVisible) {
            throw new AuthorizationException('You are not authorized to link a contact to the selected company.');
        }
    }

    /**
     * Normalize primary and secondary emails with case-insensitive deduplication.
     *
     * @param  array<mixed, mixed>  $secondaryEmails
     * @return array{
     *     rows: list<array{email: string, is_primary: bool}>,
     *     secondary_lower: list<string>
     * }
     */
    protected function normalizeEmails(?string $primaryEmail, array $secondaryEmails): array
    {
        $seenLower = [];
        /** @var list<array{email: string, is_primary: bool}> $rows */
        $rows = [];
        /** @var list<string> $secondaryLower */
        $secondaryLower = [];

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
            $secondaryLower[] = $lower;
            $rows[] = [
                'email' => $trimmed,
                'is_primary' => false,
            ];
        }

        sort($secondaryLower);

        return [
            'rows' => $rows,
            'secondary_lower' => $secondaryLower,
        ];
    }

    /**
     * Atomically replace contact_emails rows for a locked Contact inside an active transaction.
     *
     * @param  list<array{email: string, is_primary: bool}>  $rows
     */
    protected function replaceContactEmails(Contact $contact, array $rows): void
    {
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
