<?php

namespace App\Modules\Crm\Companies\Actions;

use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Data\UpsertCompanyData;
use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\CustomFields\Actions\ValidateEntityCustomFieldsAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

class CreateCompanyAction
{
    public function __construct(
        protected ValidateEntityCustomFieldsAction $validateCustomFields,
    ) {}

    public function execute(User $actor, UpsertCompanyData $data): Company
    {
        Gate::forUser($actor)->authorize('create', Company::class);

        $name = ! $data->name instanceof Optional ? trim($data->name) : '';
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['Company name is required.'],
            ]);
        }

        $domain = ! $data->domain instanceof Optional && $data->domain !== null
            ? (trim($data->domain) !== '' ? trim($data->domain) : null)
            : null;

        $accountManagerId = ! $data->account_manager_id instanceof Optional
            ? $data->account_manager_id
            : null;

        if ($accountManagerId !== null) {
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

        $rawCustomFields = ! $data->custom_fields instanceof Optional ? $data->custom_fields : [];
        $validatedCustomFields = $this->validateCustomFields->execute(
            entityType: CustomFieldEntityTypeEnum::Company,
            values: $rawCustomFields,
            pipelineId: null,
            partial: false,
        );

        $lifecycleStage = ! $data->lifecycle_stage instanceof Optional
            ? $data->lifecycle_stage
            : CompanyLifecycleStageEnum::Prospect;

        $industry = ! $data->industry instanceof Optional && $data->industry !== null
            ? (trim($data->industry) !== '' ? trim($data->industry) : null)
            : null;

        $employeeRange = ! $data->employee_range instanceof Optional && $data->employee_range !== null
            ? (trim($data->employee_range) !== '' ? trim($data->employee_range) : null)
            : null;

        $annualRevenueCents = ! $data->annual_revenue_cents instanceof Optional
            ? $data->annual_revenue_cents
            : null;

        $billingAddress = ! $data->billing_address instanceof Optional
            ? ($data->billing_address ?? [])
            : [];

        return DB::transaction(static function () use (
            $actor,
            $name,
            $domain,
            $lifecycleStage,
            $industry,
            $employeeRange,
            $annualRevenueCents,
            $billingAddress,
            $accountManagerId,
            $validatedCustomFields,
        ): Company {
            $company = Company::query()->create([
                'name' => $name,
                'domain' => $domain,
                'lifecycle_stage' => $lifecycleStage,
                'industry' => $industry,
                'employee_range' => $employeeRange,
                'annual_revenue_cents' => $annualRevenueCents,
                'billing_address' => $billingAddress,
                'owner_id' => $actor->id,
                'account_manager_id' => $accountManagerId,
                'custom_fields' => $validatedCustomFields,
            ]);

            return $company->refresh()->load(['owner:id,name', 'accountManager:id,name']);
        });
    }
}
