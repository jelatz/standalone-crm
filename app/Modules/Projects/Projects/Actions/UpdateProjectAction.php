<?php

namespace App\Modules\Projects\Projects\Actions;

use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\CustomFields\Actions\ValidateEntityCustomFieldsAction;
use App\Modules\Crm\CustomFields\Enums\CustomFieldEntityTypeEnum;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Notes\Actions\SanitizeTipTapAstAction;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Projects\Projects\Data\UpdateProjectData;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use App\Modules\Projects\Projects\Models\Project;
use App\Support\Silo\VisibleScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

class UpdateProjectAction
{
    public function __construct(
        protected SanitizeTipTapAstAction $sanitizer,
        protected ValidateEntityCustomFieldsAction $validateCustomFields,
        protected VisibleScopeResolver $scopeResolver,
        protected RecordActivityAction $recordActivity,
    ) {}

    public function execute(User $actor, Project $project, UpdateProjectData $data): Project
    {
        Gate::forUser($actor)->authorize('update', $project);

        if (! $data->name instanceof Optional && trim($data->name) === '') {
            throw ValidationException::withMessages([
                'name' => ['Project name cannot be empty.'],
            ]);
        }

        $sanitizedDescription = null;
        $hasDescriptionUpdate = ! $data->description_json instanceof Optional;
        if ($hasDescriptionUpdate && is_array($data->description_json)) {
            try {
                $sanitizedDescription = $this->sanitizer->execute($data->description_json, requireNonEmpty: false)['body_json'];
            } catch (ValidationException $e) {
                $errors = $e->errors();

                throw ValidationException::withMessages([
                    'description_json' => $errors['body_json'] ?? ['Description must be a valid TipTap doc document.'],
                ]);
            }
        }

        $validatedPartialCustomFields = ! $data->custom_fields instanceof Optional
            ? $this->validateCustomFields->execute(
                entityType: CustomFieldEntityTypeEnum::Project,
                values: $data->custom_fields,
                pipelineId: null,
                partial: true,
            )
            : null;

        return DB::transaction(function () use (
            $actor,
            $project,
            $data,
            $hasDescriptionUpdate,
            $sanitizedDescription,
            $validatedPartialCustomFields,
        ): Project {
            $lockedProject = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = [];
            $changedFields = [];

            if (! $data->name instanceof Optional) {
                $nextName = Str::limit(trim($data->name), 200, '');
                if ($nextName !== $lockedProject->name) {
                    $attributes['name'] = $nextName;
                    $changedFields[] = 'name';
                }
            }

            $nextCompanyId = $lockedProject->company_id !== null ? (string) $lockedProject->company_id : null;
            if (! $data->company_id instanceof Optional) {
                $candidateCompanyId = $data->company_id !== null && trim($data->company_id) !== ''
                    ? trim($data->company_id)
                    : null;

                if ($candidateCompanyId !== $nextCompanyId) {
                    if ($nextCompanyId !== null) {
                        $existingCompany = Company::query()->find($nextCompanyId);
                        if ($existingCompany === null || ! Gate::forUser($actor)->allows('view', $existingCompany)) {
                            throw new AuthorizationException('You are not authorized to unlink or replace an inaccessible company on this project.');
                        }
                    }

                    if ($candidateCompanyId !== null) {
                        $companyVisible = Gate::forUser($actor)->allows('viewAny', Company::class)
                            && Company::query()->visibleTo($actor)->whereKey($candidateCompanyId)->exists();
                        if (! $companyVisible) {
                            throw new AuthorizationException('You are not authorized to link this project to the selected company.');
                        }
                    }

                    $attributes['company_id'] = $candidateCompanyId;
                    $changedFields[] = 'company_id';
                    $nextCompanyId = $candidateCompanyId;
                }
            }

            $nextPrimaryDealId = $lockedProject->primary_deal_id !== null ? (string) $lockedProject->primary_deal_id : null;
            if (! $data->primary_deal_id instanceof Optional) {
                $candidateDealId = $data->primary_deal_id !== null && trim($data->primary_deal_id) !== ''
                    ? trim($data->primary_deal_id)
                    : null;

                if ($candidateDealId !== $nextPrimaryDealId) {
                    if ($nextPrimaryDealId !== null) {
                        $existingDeal = Deal::query()->find($nextPrimaryDealId);
                        if ($existingDeal === null || ! Gate::forUser($actor)->allows('view', $existingDeal)) {
                            throw new AuthorizationException('You are not authorized to unlink or replace an inaccessible deal on this project.');
                        }
                    }

                    if ($candidateDealId !== null) {
                        $dealVisible = Gate::forUser($actor)->allows('viewAny', Deal::class)
                            && Deal::query()->visibleTo($actor)->whereKey($candidateDealId)->exists();
                        if (! $dealVisible) {
                            throw new AuthorizationException('You are not authorized to link this project to the selected deal.');
                        }

                        DB::table('deal_project')->insertOrIgnore([
                            'deal_id' => $candidateDealId,
                            'project_id' => (string) $lockedProject->id,
                        ]);
                    }

                    $attributes['primary_deal_id'] = $candidateDealId;
                    $changedFields[] = 'primary_deal_id';
                    $nextPrimaryDealId = $candidateDealId;
                }
            }

            if (! $data->owner_id instanceof Optional) {
                $candidateOwnerId = trim($data->owner_id);
                if ($candidateOwnerId !== (string) $lockedProject->owner_id) {
                    $ownerExists = User::query()
                        ->whereKey($candidateOwnerId)
                        ->where('status', UserStatusEnum::Active->value)
                        ->exists();

                    if (! $ownerExists) {
                        throw ValidationException::withMessages([
                            'owner_id' => ['Project owner must be an active employee.'],
                        ]);
                    }

                    $scope = $this->scopeResolver->for($actor);
                    if (
                        ! $scope->isUnrestrictedAdmin
                        && ! Gate::forUser($actor)->allows('manageCollaborators', $lockedProject)
                        && ! in_array($candidateOwnerId, $scope->siloUserIds, true)
                    ) {
                        throw new AuthorizationException('You are not authorized to transfer project ownership outside your silo.');
                    }

                    $attributes['owner_id'] = $candidateOwnerId;
                    $changedFields[] = 'owner_id';
                }
            }

            if (! $data->account_manager_id instanceof Optional) {
                $candidateAmId = $data->account_manager_id !== null && trim($data->account_manager_id) !== ''
                    ? trim($data->account_manager_id)
                    : null;

                if ($candidateAmId !== null && $candidateAmId !== $lockedProject->account_manager_id) {
                    $amExists = User::query()
                        ->whereKey($candidateAmId)
                        ->where('status', UserStatusEnum::Active->value)
                        ->exists();

                    if (! $amExists) {
                        throw ValidationException::withMessages([
                            'account_manager_id' => ['Assigned account manager must be an active employee.'],
                        ]);
                    }
                }

                if ($candidateAmId !== $lockedProject->account_manager_id) {
                    $attributes['account_manager_id'] = $candidateAmId;
                    $changedFields[] = 'account_manager_id';
                }
            }

            if (! $data->department_id instanceof Optional && trim($data->department_id) !== (string) $lockedProject->department_id) {
                $attributes['department_id'] = trim($data->department_id);
                $changedFields[] = 'department_id';
            }

            if ($hasDescriptionUpdate && $sanitizedDescription !== $lockedProject->description_json) {
                $attributes['description_json'] = $sanitizedDescription;
                $changedFields[] = 'description_json';
            }

            if (! $data->status instanceof Optional && $data->status !== $lockedProject->status) {
                $attributes['status'] = $data->status;
                $changedFields[] = 'status';

                if ($data->status === ProjectStatusEnum::Completed) {
                    $attributes['completed_at'] = $lockedProject->completed_at ?? now();
                } else {
                    $attributes['completed_at'] = null;
                }
            }

            if (! $data->health instanceof Optional && $data->health !== $lockedProject->health) {
                $attributes['health'] = $data->health;
                $changedFields[] = 'health';
            }

            $nextStartDate = $lockedProject->start_date?->format('Y-m-d');
            if (! $data->start_date instanceof Optional) {
                $candidateStart = $data->start_date !== null && trim($data->start_date) !== ''
                    ? trim($data->start_date)
                    : null;

                if ($candidateStart !== $nextStartDate) {
                    $attributes['start_date'] = $candidateStart;
                    $changedFields[] = 'start_date';
                    $nextStartDate = $candidateStart;
                }
            }

            $nextEndDate = $lockedProject->target_end_date?->format('Y-m-d');
            if (! $data->target_end_date instanceof Optional) {
                $candidateEnd = $data->target_end_date !== null && trim($data->target_end_date) !== ''
                    ? trim($data->target_end_date)
                    : null;

                if ($candidateEnd !== $nextEndDate) {
                    $attributes['target_end_date'] = $candidateEnd;
                    $changedFields[] = 'target_end_date';
                    $nextEndDate = $candidateEnd;
                }
            }

            if ($nextStartDate !== null && $nextEndDate !== null && $nextEndDate < $nextStartDate) {
                throw ValidationException::withMessages([
                    'target_end_date' => ['Target end date must be on or after the start date.'],
                ]);
            }

            if ($validatedPartialCustomFields !== null) {
                $merged = array_merge($lockedProject->custom_fields ?? [], $validatedPartialCustomFields);
                $nextCustomFields = array_filter($merged, static fn (mixed $v): bool => $v !== null);

                if ($nextCustomFields !== ($lockedProject->custom_fields ?? [])) {
                    $attributes['custom_fields'] = $nextCustomFields;
                    $changedFields[] = 'custom_fields';
                }
            }

            if ($attributes !== []) {
                $lockedProject->update($attributes);
            }

            if ($changedFields !== []) {
                $targets = ['project' => (string) $lockedProject->id];
                if ($nextCompanyId !== null) {
                    $targets['company'] = $nextCompanyId;
                }
                if ($nextPrimaryDealId !== null) {
                    $targets['deal'] = $nextPrimaryDealId;
                }

                $this->recordActivity->execute(
                    actor: $actor,
                    activityType: ActivityTypeEnum::FieldUpdated,
                    sourceType: $lockedProject->getMorphClass(),
                    sourceId: (string) $lockedProject->id,
                    targets: $targets,
                    summary: 'Updated project fields: '.implode(', ', $changedFields),
                    metadata: ['changed_fields' => $changedFields],
                );
            }

            return $lockedProject->refresh();
        });
    }
}
