<?php

namespace App\Modules\Crm\Leads\Actions;

use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Actions\CreateCompanyAction;
use App\Modules\Crm\Companies\Data\UpsertCompanyData;
use App\Modules\Crm\Companies\Enums\CompanyLifecycleStageEnum;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Contacts\Actions\UpsertContactAction;
use App\Modules\Crm\Contacts\Data\UpsertContactData;
use App\Modules\Crm\Contacts\Models\Contact;
use App\Modules\Crm\Deals\Actions\CreateDealAction;
use App\Modules\Crm\Deals\Data\CreateDealData;
use App\Modules\Crm\Deals\Data\DealStakeholderInputData;
use App\Modules\Crm\Deals\Enums\DealStakeholderRoleEnum;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Leads\Data\ConvertLeadData;
use App\Modules\Crm\Leads\Data\ConvertLeadResultData;
use App\Modules\Crm\Leads\Data\LeadDetailData;
use App\Modules\Crm\Leads\Enums\LeadStatusEnum;
use App\Modules\Crm\Leads\Models\Lead;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConvertLeadAction
{
    public function __construct(
        protected CreateCompanyAction $createCompany,
        protected UpsertContactAction $upsertContact,
        protected CreateDealAction $createDeal,
        protected RecordActivityAction $recordActivity,
    ) {}

    public function execute(User $actor, Lead $lead, ConvertLeadData $data): ConvertLeadResultData
    {
        Gate::forUser($actor)->authorize('convert', $lead);
        Gate::forUser($actor)->authorize('create', Contact::class);

        if ($data->company_mode === 'create') {
            Gate::forUser($actor)->authorize('create', Company::class);
        }

        if ($data->create_deal) {
            Gate::forUser($actor)->authorize('create', Deal::class);
        }

        return DB::transaction(function () use ($actor, $lead, $data): ConvertLeadResultData {
            $lockedLead = Lead::query()
                ->whereKey($lead->id)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('convert', $lockedLead);

            if ($lockedLead->status === LeadStatusEnum::Converted || $lockedLead->converted_at !== null) {
                throw ValidationException::withMessages([
                    'lead' => ['This lead has already been converted.'],
                ]);
            }

            if ($data->company_mode === 'existing') {
                $existingCompanyId = $data->existing_company_id !== null ? trim($data->existing_company_id) : '';

                $company = ($existingCompanyId !== '' && Gate::forUser($actor)->allows('viewAny', Company::class))
                    ? Company::query()->visibleTo($actor)->whereKey($existingCompanyId)->lockForUpdate()->first()
                    : null;

                if ($company === null || ! Gate::forUser($actor)->allows('update', $company)) {
                    throw new AuthorizationException('You are not authorized to convert this lead into the selected company.');
                }
            } else {
                $companyName = trim((string) ($data->company_name ?? $lockedLead->company_name ?? ''));
                if ($companyName === '') {
                    throw ValidationException::withMessages([
                        'company_name' => ['Company name is required when creating a new company.'],
                    ]);
                }

                $domain = $data->company_domain !== null && trim($data->company_domain) !== ''
                    ? trim($data->company_domain)
                    : $lockedLead->website;

                $company = $this->createCompany->execute(
                    $actor,
                    new UpsertCompanyData(
                        name: $companyName,
                        domain: $domain,
                        lifecycle_stage: CompanyLifecycleStageEnum::Prospect,
                    ),
                );
            }

            $contact = $this->upsertContact->execute(
                $actor,
                new UpsertContactData(
                    company_id: (string) $company->id,
                    first_name: $lockedLead->first_name,
                    last_name: $lockedLead->last_name,
                    primary_email: $lockedLead->email,
                    phones: $lockedLead->phone !== null ? [$lockedLead->phone] : [],
                    job_title: $lockedLead->job_title,
                    is_primary_contact: $data->is_primary_contact,
                ),
            );

            $deal = null;
            if ($data->create_deal) {
                $dealTitle = trim((string) ($data->deal_title ?? ''));
                if ($dealTitle === '') {
                    throw ValidationException::withMessages([
                        'deal_title' => ['Deal title is required when creating an initial deal.'],
                    ]);
                }

                $deal = $this->createDeal->execute(
                    $actor,
                    new CreateDealData(
                        company_id: (string) $company->id,
                        title: $dealTitle,
                        primary_contact_id: (string) $contact->id,
                        pipeline_id: $data->pipeline_id,
                        stage_id: $data->stage_id,
                        amount_cents: $data->amount_cents,
                        currency: strtoupper(trim($data->currency)) ?: $lockedLead->currency,
                        expected_close_date: $data->expected_close_date,
                        win_probability: $data->win_probability,
                        stakeholders: [
                            new DealStakeholderInputData(
                                contact_id: (string) $contact->id,
                                stakeholder_role: DealStakeholderRoleEnum::DecisionMaker,
                            ),
                        ],
                    ),
                );
            }

            $lockedLead->update([
                'status' => LeadStatusEnum::Converted,
                'converted_at' => now(),
                'converted_company_id' => $company->id,
                'converted_contact_id' => $contact->id,
                'converted_deal_id' => $deal?->id,
            ]);

            $targets = [
                'lead' => (string) $lockedLead->id,
                'company' => (string) $company->id,
                'contact' => (string) $contact->id,
            ];
            if ($deal !== null) {
                $targets['deal'] = (string) $deal->id;
            }

            $fullName = trim($lockedLead->first_name.' '.$lockedLead->last_name);
            $this->recordActivity->execute(
                actor: $actor,
                activityType: ActivityTypeEnum::LeadConverted,
                sourceType: $lockedLead->getMorphClass(),
                sourceId: (string) $lockedLead->id,
                targets: $targets,
                summary: "Converted lead {$fullName}",
                metadata: [
                    'company_id' => (string) $company->id,
                    'contact_id' => (string) $contact->id,
                    'deal_id' => $deal !== null ? (string) $deal->id : null,
                ],
            );

            $refreshedLead = $lockedLead->refresh()->load([
                'owner:id,name',
                'convertedCompany:id,owner_id,account_manager_id',
                'convertedContact:id,owner_id',
                'convertedDeal:id,owner_id,account_manager_id',
            ]);

            $canViewCompany = $refreshedLead->convertedCompany !== null
                && Gate::forUser($actor)->allows('view', $refreshedLead->convertedCompany);
            $canViewContact = $refreshedLead->convertedContact !== null
                && Gate::forUser($actor)->allows('view', $refreshedLead->convertedContact);
            $canViewDeal = $refreshedLead->convertedDeal !== null
                && Gate::forUser($actor)->allows('view', $refreshedLead->convertedDeal);

            return new ConvertLeadResultData(
                lead: LeadDetailData::fromModel(
                    lead: $refreshedLead,
                    canViewConvertedCompany: $canViewCompany,
                    canViewConvertedContact: $canViewContact,
                    canViewConvertedDeal: $canViewDeal,
                ),
                company_id: $canViewCompany ? (string) $company->id : '',
                contact_id: $canViewContact ? (string) $contact->id : '',
                deal_id: $canViewDeal && $deal !== null ? (string) $deal->id : null,
            );
        });
    }
}
