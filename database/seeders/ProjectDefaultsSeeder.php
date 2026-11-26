<?php

namespace Database\Seeders;

use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Templates\Enums\TemplateAssigneeRoleHintEnum;
use App\Modules\Projects\Templates\Models\ProjectTemplate;
use App\Modules\Projects\Templates\Models\ProjectTemplateTask;
use Illuminate\Database\Seeder;

class ProjectDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $creator = $this->resolveCreator();

        $template = ProjectTemplate::query()->updateOrCreate(
            ['name' => 'Standard Client Onboarding'],
            [
                'department_id' => null,
                'default_stages' => [
                    [
                        'key' => 'backlog',
                        'name' => 'Backlog',
                        'category' => ProjectStageCategoryEnum::Backlog->value,
                        'sort_order' => 1,
                        'wip_limit' => null,
                    ],
                    [
                        'key' => 'todo',
                        'name' => 'To Do',
                        'category' => ProjectStageCategoryEnum::Todo->value,
                        'sort_order' => 2,
                        'wip_limit' => null,
                    ],
                    [
                        'key' => 'in_progress',
                        'name' => 'In Progress',
                        'category' => ProjectStageCategoryEnum::InProgress->value,
                        'sort_order' => 3,
                        'wip_limit' => null,
                    ],
                    [
                        'key' => 'review',
                        'name' => 'Review',
                        'category' => ProjectStageCategoryEnum::Review->value,
                        'sort_order' => 4,
                        'wip_limit' => null,
                    ],
                    [
                        'key' => 'done',
                        'name' => 'Done',
                        'category' => ProjectStageCategoryEnum::Done->value,
                        'sort_order' => 5,
                        'wip_limit' => null,
                    ],
                ],
                'description' => 'Default onboarding workflow for approved closed-won client handoffs.',
                'is_active' => true,
                'created_by_id' => $creator->id,
            ],
        );

        $confirmScopeTask = $this->upsertTemplateTask(
            template: $template,
            title: 'Confirm handoff scope & kickoff date',
            stageKey: 'todo',
            roleHint: TemplateAssigneeRoleHintEnum::Pm,
            relativeDueDays: 1,
            sortOrder: 1,
            description: 'Review deal handoff checklist, scope notes, and confirm target kickoff schedule.',
        );

        $this->upsertTemplateTask(
            template: $template,
            title: 'Send welcome packet & client portal intro',
            stageKey: 'todo',
            roleHint: TemplateAssigneeRoleHintEnum::Am,
            relativeDueDays: 2,
            sortOrder: 2,
            description: 'Share onboarding timeline, primary contacts, and workspace access instructions.',
        );

        $kickoffTask = $this->upsertTemplateTask(
            template: $template,
            title: 'Schedule and lead client kickoff call',
            stageKey: 'in_progress',
            roleHint: TemplateAssigneeRoleHintEnum::Pm,
            relativeDueDays: 3,
            sortOrder: 3,
            description: 'Align delivery milestones, success metrics, and weekly cadence with client stakeholders.',
        );

        $this->upsertTemplateTask(
            template: $template,
            title: 'Prepare kickoff deck and milestone agenda',
            stageKey: 'todo',
            roleHint: TemplateAssigneeRoleHintEnum::Pm,
            relativeDueDays: 2,
            sortOrder: 1,
            description: 'Customize kickoff slide deck with contracted scope and delivery milestones.',
            parentTask: $kickoffTask,
        );

        $this->upsertTemplateTask(
            template: $template,
            title: 'Confirm client stakeholder attendees',
            stageKey: 'todo',
            roleHint: TemplateAssigneeRoleHintEnum::Am,
            relativeDueDays: 2,
            sortOrder: 2,
            description: 'Verify executive sponsor, technical lead, and billing contact attendance.',
            parentTask: $kickoffTask,
        );

        $this->upsertTemplateTask(
            template: $template,
            title: 'Complete workspace configuration & QA sign-off',
            stageKey: 'backlog',
            roleHint: TemplateAssigneeRoleHintEnum::Pm,
            relativeDueDays: 7,
            sortOrder: 4,
            description: 'Provision client environment, verify integrations, and complete launch readiness review.',
        );

        // Ensure reference stays explicit without unused variable noise.
        unset($confirmScopeTask);
    }

    protected function resolveCreator(): User
    {
        $existing = User::query()
            ->where('status', UserStatusEnum::Active->value)
            ->whereHas('roles', static function ($query): void {
                $query->whereIn('name', [RoleEnum::Superadmin->value, RoleEnum::Admin->value]);
            })
            ->orderBy('created_at')
            ->first()
            ?? User::query()->orderBy('created_at')->first();

        if ($existing !== null) {
            return $existing;
        }

        $department = Department::query()->firstOrCreate(
            ['slug' => 'general'],
            ['name' => 'General'],
        );

        $email = mb_strtolower(trim((string) env('SEED_SUPERADMIN_EMAIL', 'superadmin@example.com')));

        return User::query()->firstOrCreate(
            ['email' => $email],
            [
                'employee_code' => 'EMP-0000',
                'name' => 'Super Admin',
                'password' => (string) env('SEED_SUPERADMIN_PASSWORD', 'SuperAdmin123!'),
                'department_id' => $department->id,
                'job_title' => 'Super Administrator',
                'status' => UserStatusEnum::Active,
            ],
        );
    }

    protected function upsertTemplateTask(
        ProjectTemplate $template,
        string $title,
        string $stageKey,
        TemplateAssigneeRoleHintEnum $roleHint,
        int $relativeDueDays,
        int $sortOrder,
        string $description,
        ?ProjectTemplateTask $parentTask = null,
    ): ProjectTemplateTask {
        return ProjectTemplateTask::query()->updateOrCreate(
            [
                'project_template_id' => $template->id,
                'title' => $title,
            ],
            [
                'parent_template_task_id' => $parentTask?->id,
                'stage_key' => $stageKey,
                'description_json' => [
                    'type' => 'doc',
                    'content' => [
                        [
                            'type' => 'paragraph',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => $description,
                                ],
                            ],
                        ],
                    ],
                ],
                'relative_due_days' => $relativeDueDays,
                'assignee_role_hint' => $roleHint,
                'sort_order' => $sortOrder,
            ],
        );
    }
}
