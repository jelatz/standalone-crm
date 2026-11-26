<?php

namespace App\Modules\Crm\Companies\Actions;

use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Data\UpsertCompanyData;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\CustomFields\Actions\ValidateEntityCustomFieldsAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

class UpdateCompanyAction
{
    public function __construct(
        protected ValidateEntityCustomFieldsAction $validateCustomFields,
        protected RecordActivityAction $recordActivity,
    ) {}

    public function execute(User $actor, Company $company, UpsertCompanyData $data): Company
    {
        Gate::forUser($actor)->authorize('update', $company);

        $attributes = [];
        $changedFields = [];

        if (! $data->name instanceof Optional) {
            $name = trim($data->name);
            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => ['Company name cannot be empty.'],
                ]);
            }

            if ($name !== $company->name) {
                $attributes['name'] = $name;
                $changedFields[] = 'name';
            }
        }

        if (! $data->domain instanceof Optional) {
            $domain = $data->domain !== null && trim($data->domain) !== '' ? trim($data->domain) : null;
            if ($domain !== $company->domain) {
                $attributes['domain'] = $domain;
                $changedFields[] = 'domain';
            }
        }

        if (! $data->lifecycle_stage instanceof Optional && $data->lifecycle_stage !== $company->lifecycle_stage) {
            $attributes['lifecycle_stage'] = $data->lifecycle_stage;
            $changedFields[] = 'lifecycle_stage';
        }

        if (! $data->industry instanceof Optional) {
            $industry = $data->industry !== null && trim($data->industry) !== '' ? trim($data->industry) : null;
            if ($industry !== $company->industry) {
                $attributes['industry'] = $industry;
                $changedFields[] = 'industry';
            }
        }

        if (! $data->employee_range instanceof Optional) {
            $employeeRange = $data->employee_range !== null && trim($data->employee_range) !== '' ? trim($data->employee_range) : null;
            if ($employeeRange !== $company->employee_range) {
                $attributes['employee_range'] = $employeeRange;
                $changedFields[] = 'employee_range';
            }
        }

        if (! $data->annual_revenue_cents instanceof Optional && $data->annual_revenue_cents !== $company->annual_revenue_cents) {
            $attributes['annual_revenue_cents'] = $data->annual_revenue_cents;
            $changedFields[] = 'annual_revenue_cents';
        }

        if (! $data->billing_address instanceof Optional) {
            $billingAddress = $data->billing_address ?? [];
            if ($billingAddress !== ($company->billing_address ?? [])) {
                $attributes['billing_address'] = $billingAddress;
                $changedFields[] = 'billing_address';
            }
        }

        if (! $data->account_manager_id instanceof Optional) {
            $accountManagerId = $data->account_manager_id;
            if ($accountManagerId !== null && $accountManagerId !== $company->account_manager_id) {
                $activeAccountManagerExists = User::query()
                    ->whereKey($accountManagerId)
                    ->where('status', UserStatusEnum::Active->value)
                    ->exists();

                if (! $activeAccountManagerExists) {
                    throw ValidationException::withMessages([
                        'account_manager_id' => ['The selected account manager must be an active employee.'],
                    ]);
                }
            }

            if ($accountManagerId !== $company->account_manager_id) {
                $attributes['account_manager_id'] = $accountManagerId;
                $changedFields[] = 'account_manager_id';
            }
        }

        if (! $data->custom_fields instanceof Optional) {
            $validatedPartial = $this->validateCustomFields->execute(
                entityType: CustomFieldEntityTypeEnum::Company,
                values: $data->custom_fields,
                pipelineId: null,
                partial: true,
            );

            $merged = array_merge($company->custom_fields ?? [], $validatedPartial);
            $nextCustomFields = array_filter($merged, static fn (mixed $v): bool => $v !== null);

            if ($nextCustomFields !== ($company->custom_fields ?? [])) {
                $attributes['custom_fields'] = $nextCustomFields;
                $changedFields[] = 'custom_fields';
            }
        }

        return DB::transaction(function () use ($actor, $company, $attributes, $changedFields): Company {
            if ($attributes !== []) {
                $company->update($attributes);
            }

            if ($changedFields !== []) {
                $this->recordActivity->execute(
                    actor: $actor,
                    activityType: ActivityTypeEnum::FieldUpdated,
                    sourceType: $company->getMorphClass(),
                    sourceId: (string) $company->id,
                    targets: ['company' => (string) $company->id],
                    summary: 'Updated company fields: '.implode(', ', $changedFields),
                    metadata: ['changed_fields' => $changedFields],
                );
            }

            return $company->refresh()->load(['owner:id,name', 'accountManager:id,name']);
        });
    }
}
