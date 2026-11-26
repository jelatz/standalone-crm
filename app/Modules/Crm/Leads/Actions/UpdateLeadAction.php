<?php

namespace App\Modules\Crm\Leads\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\CustomFields\Actions\ValidateEntityCustomFieldsAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Leads\Data\UpsertLeadData;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Leads\Models\Lead;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

class UpdateLeadAction
{
    public function __construct(
        protected ValidateEntityCustomFieldsAction $validateCustomFields,
        protected RecordActivityAction $recordActivity,
    ) {}

    public function execute(User $actor, Lead $lead, UpsertLeadData $data): Lead
    {
        Gate::forUser($actor)->authorize('update', $lead);

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

        if (! $data->status instanceof Optional && $data->status === LeadStatusEnum::Converted) {
            throw ValidationException::withMessages([
                'status' => ['Use the Convert Lead workflow to mark a lead as converted.'],
            ]);
        }

        $validatedPartialCustomFields = ! $data->custom_fields instanceof Optional
            ? $this->validateCustomFields->execute(
                entityType: CustomFieldEntityTypeEnum::Lead,
                values: $data->custom_fields,
                pipelineId: null,
                partial: true,
            )
            : null;

        return DB::transaction(function () use ($actor, $lead, $data, $validatedPartialCustomFields): Lead {
            $lockedLead = Lead::query()
                ->whereKey($lead->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedLead->status === LeadStatusEnum::Converted && ! $data->status instanceof Optional) {
                throw ValidationException::withMessages([
                    'status' => ['Converted leads cannot be moved back to an unconverted status.'],
                ]);
            }

            $attributes = [];
            $changedFields = [];

            if (! $data->first_name instanceof Optional) {
                $firstName = trim($data->first_name);
                if ($firstName !== $lockedLead->first_name) {
                    $attributes['first_name'] = $firstName;
                    $changedFields[] = 'first_name';
                }
            }

            if (! $data->last_name instanceof Optional) {
                $lastName = trim($data->last_name);
                if ($lastName !== $lockedLead->last_name) {
                    $attributes['last_name'] = $lastName;
                    $changedFields[] = 'last_name';
                }
            }

            if (! $data->email instanceof Optional) {
                $email = $data->email !== null && trim($data->email) !== ''
                    ? mb_strtolower(trim($data->email))
                    : null;
                if ($email !== $lockedLead->email) {
                    $attributes['email'] = $email;
                    $changedFields[] = 'email';
                }
            }

            if (! $data->phone instanceof Optional) {
                $phone = $data->phone !== null && trim($data->phone) !== '' ? trim($data->phone) : null;
                if ($phone !== $lockedLead->phone) {
                    $attributes['phone'] = $phone;
                    $changedFields[] = 'phone';
                }
            }

            if (! $data->job_title instanceof Optional) {
                $jobTitle = $data->job_title !== null && trim($data->job_title) !== '' ? trim($data->job_title) : null;
                if ($jobTitle !== $lockedLead->job_title) {
                    $attributes['job_title'] = $jobTitle;
                    $changedFields[] = 'job_title';
                }
            }

            if (! $data->company_name instanceof Optional) {
                $companyName = $data->company_name !== null && trim($data->company_name) !== '' ? trim($data->company_name) : null;
                if ($companyName !== $lockedLead->company_name) {
                    $attributes['company_name'] = $companyName;
                    $changedFields[] = 'company_name';
                }
            }

            if (! $data->website instanceof Optional) {
                $website = $data->website !== null && trim($data->website) !== '' ? trim($data->website) : null;
                if ($website !== $lockedLead->website) {
                    $attributes['website'] = $website;
                    $changedFields[] = 'website';
                }
            }

            if (! $data->status instanceof Optional && $data->status !== $lockedLead->status) {
                $attributes['status'] = $data->status;
                $changedFields[] = 'status';
            }

            if (! $data->source instanceof Optional && $data->source !== $lockedLead->source) {
                $attributes['source'] = $data->source;
                $changedFields[] = 'source';
            }

            if (! $data->estimated_value_cents instanceof Optional && $data->estimated_value_cents !== $lockedLead->estimated_value_cents) {
                $attributes['estimated_value_cents'] = $data->estimated_value_cents;
                $changedFields[] = 'estimated_value_cents';
            }

            if (! $data->currency instanceof Optional) {
                $currency = strtoupper(trim($data->currency));
                if ($currency !== '' && $currency !== $lockedLead->currency) {
                    $attributes['currency'] = $currency;
                    $changedFields[] = 'currency';
                }
            }

            if (! $data->disqualified_reason instanceof Optional) {
                $reason = $data->disqualified_reason !== null && trim($data->disqualified_reason) !== ''
                    ? trim($data->disqualified_reason)
                    : null;
                if ($reason !== $lockedLead->disqualified_reason) {
                    $attributes['disqualified_reason'] = $reason;
                    $changedFields[] = 'disqualified_reason';
                }
            }

            if ($validatedPartialCustomFields !== null) {
                $merged = array_merge($lockedLead->custom_fields ?? [], $validatedPartialCustomFields);
                $nextCustomFields = array_filter($merged, static fn (mixed $v): bool => $v !== null);

                if ($nextCustomFields !== ($lockedLead->custom_fields ?? [])) {
                    $attributes['custom_fields'] = $nextCustomFields;
                    $changedFields[] = 'custom_fields';
                }
            }

            if ($attributes !== []) {
                $lockedLead->update($attributes);
            }

            if ($changedFields !== []) {
                $this->recordActivity->execute(
                    actor: $actor,
                    activityType: ActivityTypeEnum::FieldUpdated,
                    sourceType: $lockedLead->getMorphClass(),
                    sourceId: (string) $lockedLead->id,
                    targets: ['lead' => (string) $lockedLead->id],
                    summary: 'Updated lead fields: '.implode(', ', $changedFields),
                    metadata: ['changed_fields' => $changedFields],
                );
            }

            return $lockedLead->refresh()->load('owner:id,name');
        });
    }
}
