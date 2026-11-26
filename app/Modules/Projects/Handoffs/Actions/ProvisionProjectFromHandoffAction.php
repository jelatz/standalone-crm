<?php

namespace App\Modules\Projects\Handoffs\Actions;

use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Timeline\Actions\RecordActivityAction;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Projects\Handoffs\Enums\DealHandoffStatusEnum;
use App\Modules\Projects\Handoffs\Models\DealHandoff;
use App\Modules\Projects\Handoffs\Support\DealHandoffStateMachine;
use App\Modules\Projects\Projects\Enums\ProjectHealthEnum;
use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Projects\Enums\ProjectStatusEnum;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Projects\Models\ProjectStage;
use App\Modules\Projects\Tasks\Enums\TaskPriorityEnum;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use App\Modules\Projects\Tasks\Models\Task;
use App\Modules\Projects\Templates\Enums\TemplateAssigneeRoleHintEnum;
use App\Modules\Projects\Templates\Models\ProjectTemplate;
use App\Modules\Projects\Templates\Models\ProjectTemplateTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProvisionProjectFromHandoffAction
{
    private const float BOARD_POSITION_STEP = 65536.0;

    public function __construct(
        protected GrantEntityCollaboratorAction $grantCollaborator,
        protected RecordActivityAction $recordActivity,
        protected BroadcastHandoffStatusAction $broadcastStatus,
    ) {}

    public function execute(string $handoffId, ?string $projectName = null): Project
    {
        return DB::transaction(function () use ($handoffId, $projectName): Project {
            $lockedHandoff = DealHandoff::query()
                ->whereKey($handoffId)
                ->lockForUpdate()
                ->firstOrFail();

            $existingProject = Project::withTrashed()
                ->where('deal_handoff_id', $lockedHandoff->id)
                ->first();

            if ($existingProject !== null) {
                if ($lockedHandoff->status !== DealHandoffStatusEnum::Provisioned) {
                    DealHandoffStateMachine::assertCanTransition($lockedHandoff->status, DealHandoffStatusEnum::Provisioned);
                    $lockedHandoff->forceFill([
                        'status' => DealHandoffStatusEnum::Provisioned,
                        'provisioned_at' => $lockedHandoff->provisioned_at ?? now(),
                    ])->save();
                }

                return $existingProject;
            }

            DealHandoffStateMachine::assertCanTransition($lockedHandoff->status, DealHandoffStatusEnum::Provisioned);

            $template = ProjectTemplate::query()
                ->with(['tasks' => static fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
                ->findOrFail($lockedHandoff->project_template_id);

            $deal = Deal::withTrashed()->findOrFail($lockedHandoff->deal_id);
            $company = Company::withTrashed()->findOrFail($lockedHandoff->company_id);
            $pm = User::withTrashed()->findOrFail($lockedHandoff->assigned_pm_id);
            $am = $lockedHandoff->assigned_am_id !== null
                ? User::withTrashed()->find($lockedHandoff->assigned_am_id)
                : null;

            $actor = $this->resolveProvisioningActor($lockedHandoff, $pm);
            $resolvedProjectName = $this->resolveProjectName($lockedHandoff, $deal, $projectName);
            $departmentId = $pm->department_id ?? $template->department_id;
            $code = $this->generateProjectCode();

            $project = Project::query()->create([
                'code' => $code,
                'company_id' => $company->id,
                'primary_deal_id' => $deal->id,
                'deal_handoff_id' => $lockedHandoff->id,
                'owner_id' => $pm->id,
                'account_manager_id' => $am?->id,
                'department_id' => $departmentId,
                'name' => $resolvedProjectName,
                'description_json' => $lockedHandoff->scope_notes_json,
                'status' => ProjectStatusEnum::Active,
                'health' => ProjectHealthEnum::OnTrack,
                'start_date' => $lockedHandoff->target_kickoff_date?->format('Y-m-d'),
            ]);

            $stageMap = $this->createStagesFromTemplate($project, $template);
            $this->createTasksFromTemplate($project, $template, $lockedHandoff, $stageMap, $actor, $pm, $am);

            DB::table('deal_project')->insertOrIgnore([
                'deal_id' => (string) $deal->id,
                'project_id' => (string) $project->id,
            ]);

            $this->grantCollaborator->execute(
                collaboratable: $company,
                user: $pm,
                accessLevel: CollaboratorAccessLevel::Viewer,
                roleLabel: CollaboratorRoleLabel::ProjectManager,
                grantedBy: $actor,
            );

            $this->grantCollaborator->execute(
                collaboratable: $deal,
                user: $pm,
                accessLevel: CollaboratorAccessLevel::Viewer,
                roleLabel: CollaboratorRoleLabel::ProjectManager,
                grantedBy: $actor,
            );

            if ($am !== null) {
                $this->grantCollaborator->execute(
                    collaboratable: $project,
                    user: $am,
                    accessLevel: CollaboratorAccessLevel::Editor,
                    roleLabel: CollaboratorRoleLabel::AccountManager,
                    grantedBy: $actor,
                );
            }

            $this->recordActivity->execute(
                actor: $actor,
                activityType: ActivityTypeEnum::ProjectProvisioned,
                sourceType: $project->getMorphClass(),
                sourceId: (string) $project->id,
                targets: [
                    'deal' => (string) $deal->id,
                    'company' => (string) $company->id,
                    'project' => (string) $project->id,
                ],
                summary: "Provisioned project {$project->code}: {$project->name}",
                metadata: [
                    'handoff_id' => (string) $lockedHandoff->id,
                    'project_code' => $project->code,
                    'project_template_id' => (string) $template->id,
                ],
            );

            $lockedHandoff->forceFill([
                'status' => DealHandoffStatusEnum::Provisioned,
                'provisioned_at' => now(),
            ])->save();

            $this->broadcastStatus->afterCommitToStakeholders($lockedHandoff);

            return $project->refresh();
        });
    }

    protected function resolveProvisioningActor(DealHandoff $handoff, User $fallbackPm): User
    {
        if ($handoff->reviewed_by_id !== null) {
            $reviewer = User::withTrashed()->find($handoff->reviewed_by_id);
            if ($reviewer !== null) {
                return $reviewer;
            }
        }

        $submitter = User::withTrashed()->find($handoff->submitted_by_id);

        return $submitter ?? $fallbackPm;
    }

    protected function resolveProjectName(DealHandoff $handoff, Deal $deal, ?string $explicitName): string
    {
        if ($explicitName !== null && trim($explicitName) !== '') {
            return Str::limit(trim($explicitName), 200, '');
        }

        $approvedActivity = Activity::query()
            ->where('source_type', $handoff->getMorphClass())
            ->where('source_id', (string) $handoff->id)
            ->where('activity_type', ActivityTypeEnum::HandoffApproved->value)
            ->orderByDesc('occurred_at')
            ->first();

        $activityName = $approvedActivity?->metadata['project_name'] ?? null;
        if (is_string($activityName) && trim($activityName) !== '') {
            return Str::limit(trim($activityName), 200, '');
        }

        return Str::limit(trim($deal->title) !== '' ? trim($deal->title) : 'Provisioned Project', 200, '');
    }

    protected function generateProjectCode(): string
    {
        $row = DB::selectOne("SELECT nextval('project_code_seq') AS seq");

        return sprintf('PRJ-%s-%04d', now()->format('Y'), (int) $row->seq);
    }

    /**
     * @return array<string, ProjectStage>
     */
    protected function createStagesFromTemplate(Project $project, ProjectTemplate $template): array
    {
        $stageMap = [];
        $definitions = is_array($template->default_stages) ? $template->default_stages : [];

        foreach (array_values($definitions) as $index => $stageDef) {
            if (! is_array($stageDef)) {
                continue;
            }

            $rawName = trim((string) ($stageDef['name'] ?? 'Stage '.($index + 1)));
            $stageKey = trim((string) ($stageDef['key'] ?? Str::slug($rawName, '_')));
            $category = ProjectStageCategoryEnum::tryFrom((string) ($stageDef['category'] ?? ''))
                ?? ProjectStageCategoryEnum::Todo;

            $stage = ProjectStage::query()->create([
                'project_id' => $project->id,
                'name' => Str::limit($rawName !== '' ? $rawName : 'Stage '.($index + 1), 80, ''),
                'category' => $category,
                'sort_order' => isset($stageDef['sort_order']) ? (int) $stageDef['sort_order'] : ($index + 1),
                'wip_limit' => isset($stageDef['wip_limit']) && is_numeric($stageDef['wip_limit'])
                    ? (int) $stageDef['wip_limit']
                    : null,
            ]);

            if ($stageKey !== '') {
                $stageMap[$stageKey] = $stage;
            }
        }

        return $stageMap;
    }

    /**
     * @param  array<string, ProjectStage>  $stageMap
     */
    protected function createTasksFromTemplate(
        Project $project,
        ProjectTemplate $template,
        DealHandoff $handoff,
        array $stageMap,
        User $actor,
        User $pm,
        ?User $am,
    ): void {
        $fallbackStage = $stageMap !== [] ? reset($stageMap) : null;
        $kickoffBase = $handoff->target_kickoff_date !== null
            ? CarbonImmutable::instance($handoff->target_kickoff_date)->startOfDay()
            : now()->toImmutable()->startOfDay();

        /** @var array<string, Task> $createdTaskMap */
        $createdTaskMap = [];
        /** @var array<string, int> $stageCounters */
        $stageCounters = [];

        $remaining = $template->tasks->values()->all();

        // Topological pass: creates root tasks first, then dependent subtasks preserving parent_task_id.
        $maxPasses = max(1, count($remaining) + 1);
        for ($pass = 0; $pass < $maxPasses && $remaining !== []; $pass++) {
            $nextRemaining = [];
            $progressed = false;

            foreach ($remaining as $tplTask) {
                $parentTplId = $tplTask->parent_template_task_id !== null
                    ? (string) $tplTask->parent_template_task_id
                    : null;

                if ($parentTplId !== null && ! isset($createdTaskMap[$parentTplId])) {
                    $nextRemaining[] = $tplTask;

                    continue;
                }

                $task = $this->createSingleTaskFromTemplate(
                    project: $project,
                    tplTask: $tplTask,
                    parentTaskId: $parentTplId !== null ? (string) $createdTaskMap[$parentTplId]->id : null,
                    stageMap: $stageMap,
                    fallbackStage: $fallbackStage instanceof ProjectStage ? $fallbackStage : null,
                    stageCounters: $stageCounters,
                    kickoffBase: $kickoffBase,
                    actor: $actor,
                    pm: $pm,
                    am: $am,
                );

                $createdTaskMap[(string) $tplTask->id] = $task;
                $progressed = true;
            }

            $remaining = $nextRemaining;

            if (! $progressed) {
                break;
            }
        }

        // Defensive fallback if an orphaned parent_template_task_id existed
        foreach ($remaining as $tplTask) {
            $task = $this->createSingleTaskFromTemplate(
                project: $project,
                tplTask: $tplTask,
                parentTaskId: null,
                stageMap: $stageMap,
                fallbackStage: $fallbackStage instanceof ProjectStage ? $fallbackStage : null,
                stageCounters: $stageCounters,
                kickoffBase: $kickoffBase,
                actor: $actor,
                pm: $pm,
                am: $am,
            );
            $createdTaskMap[(string) $tplTask->id] = $task;
        }
    }

    /**
     * @param  array<string, ProjectStage>  $stageMap
     * @param  array<string, int>  $stageCounters
     */
    protected function createSingleTaskFromTemplate(
        Project $project,
        ProjectTemplateTask $tplTask,
        ?string $parentTaskId,
        array $stageMap,
        ?ProjectStage $fallbackStage,
        array &$stageCounters,
        CarbonImmutable $kickoffBase,
        User $actor,
        User $pm,
        ?User $am,
    ): Task {
        $stage = $stageMap[$tplTask->stage_key] ?? $fallbackStage;
        $stageKey = $stage !== null ? (string) $stage->id : '__none__';
        $stageCounters[$stageKey] = ($stageCounters[$stageKey] ?? 0) + 1;

        $assigneeId = match ($tplTask->assignee_role_hint) {
            TemplateAssigneeRoleHintEnum::Pm => (string) $pm->id,
            TemplateAssigneeRoleHintEnum::Am => $am !== null ? (string) $am->id : null,
            default => null,
        };

        $dueAt = $tplTask->relative_due_days !== null
            ? $kickoffBase->addDays((int) $tplTask->relative_due_days)
            : null;

        return Task::query()->create([
            'project_id' => $project->id,
            'project_stage_id' => $stage?->id,
            'parent_task_id' => $parentTaskId,
            'linkable_type' => null,
            'linkable_id' => null,
            'creator_id' => $actor->id,
            'assignee_id' => $assigneeId,
            'title' => Str::limit($tplTask->title, 255, ''),
            'description_json' => $tplTask->description_json,
            'priority' => TaskPriorityEnum::Medium,
            'status' => TaskStatusEnum::Todo,
            'board_position' => $stageCounters[$stageKey] * self::BOARD_POSITION_STEP,
            'due_at' => $dueAt,
        ]);
    }
}
