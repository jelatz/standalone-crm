<?php

namespace App\Modules\Crm\Leads\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\CustomFields\Actions\ValidateEntityCustomFieldsAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Leads\Data\UpsertLeadData;
use App\Modules\Crm\Leads\Enums\LeadSourceEnum;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Leads\Models\Lead;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

class CreateLeadAction
{
    public function __construct(
        protected ValidateEntityCustomFieldsAction $validateCustomFields,
        protected RecordActivityAction $recordActivity,
    ) {}

    public function execute(User $actor, UpsertLeadData $data): Lead
    {
        Gate::forUser($actor)->authorize('create', Lead::class);

        $firstName = ! $data->first_name instanceof Optional ? trim($data->first_name) : '';
        $lastName = ! $data->last_name instanceof Optional ? trim($data->last_name) : '';

        if ($firstName === '' || $lastName === '') {
            throw ValidationException::withMessages([
                'first_name' => ['First name and last name are required.'],
            ]);
        }

        $status = ! $data->status instanceof Optional ? $data->status : LeadStatusEnum::New;
        if ($status === LeadStatusEnum::Converted) {
            throw ValidationException::withMessages([
                'status' => ['A new lead cannot be created in converted status.'],
            ]);
        }

        $source = ! $data->source instanceof Optional ? $data->source : LeadSourceEnum::InboundWeb;
        $email = ! $data->email instanceof Optional && $data->email !== null && trim($data->email) !== ''
            ? mb_strtolower(trim($data->email))
            : null;
        $phone = ! $data->phone instanceof Optional && $data->phone !== null && trim($data->phone) !== ''
            ? trim($data->phone)
            : null;
        $jobTitle = ! $data->job_title instanceof Optional && $data->job_title !== null && trim($data->job_title) !== ''
            ? trim($data->job_title)
            : null;
        $companyName = ! $data->company_name instanceof Optional && $data->company_name !== null && trim($data->company_name) !== ''
            ? trim($data->company_name)
            : null;
        $website = ! $data->website instanceof Optional && $data->website !== null && trim($data->website) !== ''
            ? trim($data->website)
            : null;
        $estimatedValueCents = ! $data->estimated_value_cents instanceof Optional
            ? $data->estimated_value_cents
            : null;
        $currency = ! $data->currency instanceof Optional && trim($data->currency) !== ''
            ? strtoupper(trim($data->currency))
            : 'USD';
        $disqualifiedReason = ! $data->disqualified_reason instanceof Optional && $data->disqualified_reason !== null && trim($data->disqualified_reason) !== ''
            ? trim($data->disqualified_reason)
            : null;

        $rawCustomFields = ! $data->custom_fields instanceof Optional ? $data->custom_fields : [];
        $validatedCustomFields = $this->validateCustomFields->execute(
            entityType: CustomFieldEntityTypeEnum::Lead,
            values: $rawCustomFields,
            pipelineId: null,
            partial: false,
        );

        return DB::transaction(function () use (
            $actor,
            $firstName,
            $lastName,
            $email,
            $phone,
            $jobTitle,
            $companyName,
            $website,
            $status,
            $source,
            $estimatedValueCents,
            $currency,
            $disqualifiedReason,
            $validatedCustomFields,
        ): Lead {
            $lead = Lead::query()->create([
                'owner_id' => $actor->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'job_title' => $jobTitle,
                'company_name' => $companyName,
                'website' => $website,
                'status' => $status,
                'source' => $source,
                'estimated_value_cents' => $estimatedValueCents,
                'currency' => $currency,
                'disqualified_reason' => $disqualifiedReason,
                'custom_fields' => $validatedCustomFields,
            ]);

            $fullName = trim($firstName.' '.$lastName);
            $this->recordActivity->execute(
                actor: $actor,
                activityType: ActivityTypeEnum::LeadCreated,
                sourceType: $lead->getMorphClass(),
                sourceId: (string) $lead->id,
                targets: ['lead' => (string) $lead->id],
                summary: "Created lead {$fullName}".($companyName !== null ? " ({$companyName})" : ''),
                metadata: [
                    'source' => $source->value,
                    'status' => $status->value,
                ],
            );

            return $lead->refresh()->load('owner:id,name');
        });
    }
}
