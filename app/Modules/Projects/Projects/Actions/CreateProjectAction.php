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
use App\Modules\Projects\Projects\Data\CreateProjectData;
use App\Modules\Projects\Projects\Data\ProjectStageInputData;
use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Projects\Models\ProjectStage;
use App\Support\Silo\VisibleScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateProjectAction
{
    public function __construct(
        protected SanitizeTipTapAstAction $sanitizer,
        protected ValidateEntityCustomFieldsAction $validateCustomFields,
        protected VisibleScopeResolver $scopeResolver,
        protected RecordActivityAction $recordActivity,
    ) {}

    public function execute(User $actor, CreateProjectData $data): Project
    {
        Gate::forUser($actor)->authorize('create', Project::class);

        $name = trim($data->name);
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['Project name is required.'],
            ]);
        }

        $owner = $actor;
        if ($data->owner_id !== null && trim($data->owner_id) !== '') {
            $ownerId = trim($data->owner_id);
            $candidateOwner = User::query()
                ->whereKey($ownerId)
                ->where('status', UserStatusEnum::Active->value)
                ->first();

            if ($candidateOwner === null) {
                throw ValidationException::withMessages([
                    'owner_id' => ['Project owner must be an active employee.'],
                ]);
            }

            if ($ownerId !== (string) $actor->id) {
                $scope = $this->scopeResolver->for($actor);
                if (! $scope->isUnrestrictedAdmin && ! in_array($ownerId, $scope->siloUserIds, true)) {
                    throw new AuthorizationException('You are not authorized to assign project ownership outside your silo.');
                }
            }

            $owner = $candidateOwner;
        }

        $accountManagerId = null;
        if ($data->account_manager_id !== null && trim($data->account_manager_id) !== '') {
            $accountManagerId = trim($data->account_manager_id);
            $amExists = User::query()
                ->whereKey($accountManagerId)
                ->where('status', UserStatusEnum::Active->value)
                ->exists();

            if (! $amExists) {
                throw ValidationException::withMessages([
                    'account_manager_id' => ['Assigned account manager must be an active employee.'],
                ]);
            }
        }

        $departmentId = $data->department_id !== null && trim($data->department_id) !== ''
            ? trim($data->department_id)
            : (string) $owner->department_id;

        $startDate = $data->start_date !== null && trim($data->start_date) !== ''
            ? trim($data->start_date)
            : null;
        $targetEndDate = $data->target_end_date !== null && trim($data->target_end_date) !== ''
            ? trim($data->target_end_date)
            : null;

        if ($startDate !== null && $targetEndDate !== null && $targetEndDate < $startDate) {
            throw ValidationException::withMessages([
                'target_end_date' => ['Target end date must be on or after the start date.'],
            ]);
        }

        $descriptionJson = $this->sanitizeDescription($data->description_json);

        $validatedCustomFields = $this->validateCustomFields->execute(
            entityType: CustomFieldEntityTypeEnum::Project,
            values: $data->custom_fields,
            pipelineId: null,
            partial: false,
        );

        $companyId = $data->company_id !== null && trim($data->company_id) !== ''
            ? trim($data->company_id)
            : null;
        $primaryDealId = $data->primary_deal_id !== null && trim($data->primary_deal_id) !== ''
            ? trim($data->primary_deal_id)
            : null;

        return DB::transaction(function () use (
            $actor,
            $owner,
            $accountManagerId,
            $departmentId,
            $companyId,
            $primaryDealId,
            $name,
            $descriptionJson,
            $data,
            $startDate,
            $targetEndDate,
            $validatedCustomFields,
        ): Project {
            if ($companyId !== null) {
                $companyVisible = Gate::forUser($actor)->allows('viewAny', Company::class)
                    && Company::query()->visibleTo($actor)->whereKey($companyId)->exists();

                if (! $companyVisible) {
                    throw new AuthorizationException('You are not authorized to link a project to the selected company.');
                }
            }

            if ($primaryDealId !== null) {
                $dealVisible = Gate::forUser($actor)->allows('viewAny', Deal::class)
                    && Deal::query()->visibleTo($actor)->whereKey($primaryDealId)->exists();

                if (! $dealVisible) {
                    throw new AuthorizationException('You are not authorized to link a project to the selected deal.');
                }
            }

            $code = $this->generateProjectCode();

            $project = Project::query()->create([
                'code' => $code,
                'company_id' => $companyId,
                'primary_deal_id' => $primaryDealId,
                'owner_id' => $owner->id,
                'account_manager_id' => $accountManagerId,
                'department_id' => $departmentId,
                'name' => Str::limit($name, 200, ''),
                'description_json' => $descriptionJson,
                'status' => $data->status,
                'health' => $data->health,
                'start_date' => $startDate,
                'target_end_date' => $targetEndDate,
                'completed_at' => $data->status === ProjectStatusEnum::Completed ? now() : null,
                'custom_fields' => $validatedCustomFields,
            ]);

            if ($primaryDealId !== null) {
                DB::table('deal_project')->insertOrIgnore([
                    'deal_id' => $primaryDealId,
                    'project_id' => (string) $project->id,
                ]);
            }

            $this->createStages($project, $data->stages);

            $targets = ['project' => (string) $project->id];
            if ($companyId !== null) {
                $targets['company'] = $companyId;
            }
            if ($primaryDealId !== null) {
                $targets['deal'] = $primaryDealId;
            }

            $this->recordActivity->execute(
                actor: $actor,
                activityType: ActivityTypeEnum::ProjectProvisioned,
                sourceType: $project->getMorphClass(),
                sourceId: (string) $project->id,
                targets: $targets,
                summary: "Created project {$project->code}: {$project->name}",
                metadata: [
                    'project_code' => $project->code,
                    'manual' => true,
                ],
            );

            return $project->refresh();
        });
    }

    protected function generateProjectCode(): string
    {
        if (DB::getDriverName() === 'pgsql') {
            $row = DB::selectOne("SELECT nextval('project_code_seq') AS seq");

            return sprintf('PRJ-%s-%04d', now()->format('Y'), (int) $row->seq);
        }

        $count = Project::withTrashed()->count() + 1;

        return sprintf('PRJ-%s-%04d', now()->format('Y'), $count);
    }

    /**
     * @param  array<mixed, mixed>|null  $raw
     * @return array<string, mixed>|null
     */
    protected function sanitizeDescription(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        try {
            $sanitized = $this->sanitizer->execute($raw, requireNonEmpty: false);

            return $sanitized['body_json'];
        } catch (ValidationException $e) {
            $errors = $e->errors();

            throw ValidationException::withMessages([
                'description_json' => $errors['body_json'] ?? ['Description must be a valid TipTap doc document.'],
            ]);
        }
    }

    /**
     * @param  list<ProjectStageInputData>|null  $stages
     */
    protected function createStages(Project $project, ?array $stages): void
    {
        if ($stages !== null && $stages !== []) {
            foreach (array_values($stages) as $index => $stageInput) {
                ProjectStage::query()->create([
                    'project_id' => $project->id,
                    'name' => Str::limit(trim($stageInput->name), 80, ''),
                    'category' => $stageInput->category,
                    'sort_order' => $stageInput->sort_order ?? ($index + 1),
                    'wip_limit' => $stageInput->wip_limit,
                ]);
            }

            return;
        }

        $defaults = [
            ['name' => 'Backlog', 'category' => ProjectStageCategoryEnum::Backlog, 'sort_order' => 1],
            ['name' => 'To Do', 'category' => ProjectStageCategoryEnum::Todo, 'sort_order' => 2],
            ['name' => 'In Progress', 'category' => ProjectStageCategoryEnum::InProgress, 'sort_order' => 3],
            ['name' => 'Review', 'category' => ProjectStageCategoryEnum::Review, 'sort_order' => 4],
            ['name' => 'Done', 'category' => ProjectStageCategoryEnum::Done, 'sort_order' => 5],
        ];

        foreach ($defaults as $stageDef) {
            ProjectStage::query()->create([
                'project_id' => $project->id,
                'name' => $stageDef['name'],
                'category' => $stageDef['category'],
                'sort_order' => $stageDef['sort_order'],
                'wip_limit' => null,
            ]);
        }
    }
}
