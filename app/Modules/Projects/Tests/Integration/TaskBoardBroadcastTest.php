<?php

use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Projects\Models\ProjectStage;
use App\Modules\Projects\Tasks\Actions\CreateTaskAction;
use App\Modules\Projects\Tasks\Actions\MoveTaskAction;
use App\Modules\Projects\Tasks\Data\CreateTaskData;
use App\Modules\Projects\Tasks\Data\MoveTaskData;
use App\Modules\Projects\Tasks\Events\TaskBoardChanged;
use App\Modules\Projects\Tasks\Models\Task;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

$createTaskBroadcastUser = static function (
    Department $department,
    string $code,
    string $email,
    RoleEnum $role,
): User {
    $user = User::query()->create([
        'employee_code' => $code,
        'name' => $code,
        'email' => $email,
        'department_id' => $department->id,
        'status' => UserStatusEnum::Active,
    ]);

    $user->assignRole($role->value);
    app(RebuildUserHierarchyClosureAction::class)->execute($user);

    return $user;
};

beforeEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesPermissionsSeeder::class);
});

afterEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->truncateTablesForAllConnections();
});

it('broadcasts ID-only TaskBoardChanged on presence-project-board.{projectId} ONLY after commit for project tasks', function () use ($createTaskBroadcastUser): void {
    $dept = Department::query()->create(['name' => 'Delivery', 'slug' => 'tb-bcast-deliv']);
    $pm = $createTaskBroadcastUser($dept, 'EMP-TB-PM', 'pm.tb@acme.internal', RoleEnum::ProjectManager);
    $director = $createTaskBroadcastUser($dept, 'EMP-TB-DIR', 'dir.tb@acme.internal', RoleEnum::Director);

    $company = Company::query()->create(['name' => 'Broadcast Client', 'owner_id' => $director->id]);

    $project = Project::query()->create([
        'code' => 'PRJ-2026-6001',
        'owner_id' => $pm->id,
        'department_id' => $dept->id,
        'name' => 'Confidential Board Project',
    ]);
    $todoStage = ProjectStage::query()->create([
        'project_id' => $project->id,
        'name' => 'To Do',
        'category' => ProjectStageCategoryEnum::Todo,
        'sort_order' => 1,
    ]);
    $doneStage = ProjectStage::query()->create([
        'project_id' => $project->id,
        'name' => 'Done',
        'category' => ProjectStageCategoryEnum::Done,
        'sort_order' => 2,
    ]);

    Event::fake([TaskBoardChanged::class]);

    // 1. CreateTaskAction broadcasts after commit
    $createdRes = $this->actingAs($pm)->postJson('/api/internal/tasks', [
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'title' => 'Top Secret Deliverable',
    ])->assertCreated();
    $taskId = (string) $createdRes->json('id');

    $dispatched = Event::dispatched(TaskBoardChanged::class);
    expect($dispatched)->toHaveCount(1);
    /** @var TaskBoardChanged $createEvent */
    $createEvent = $dispatched[0][0];
    expect($createEvent)->toBeInstanceOf(TaskBoardChanged::class)
        ->and($createEvent->broadcastOn())->toHaveCount(1)
        ->and($createEvent->broadcastOn()[0])->toBeInstanceOf(PresenceChannel::class)
        ->and($createEvent->broadcastOn()[0]->name)->toBe("presence-project-board.{$project->id}")
        ->and($createEvent->broadcastWith())->toBe([
            'task_id' => $taskId,
            'project_id' => $project->id,
            'project_stage_id' => $todoStage->id,
            'board_position' => 65536.0,
            'version' => 1,
            'status' => 'todo',
        ])
        ->and(array_keys($createEvent->broadcastWith()))->not->toContain('title', 'description_json');

    // 2. MoveTaskAction broadcasts after commit
    Event::fake([TaskBoardChanged::class]);
    $this->actingAs($pm)->patchJson("/api/internal/tasks/{$taskId}/move", [
        'target_stage_id' => $doneStage->id,
        'expected_version' => 1,
    ])->assertOk();

    Event::assertDispatchedTimes(TaskBoardChanged::class, 1);
    Event::assertDispatched(TaskBoardChanged::class, static fn (TaskBoardChanged $event): bool => $event->taskId === $taskId
        && $event->projectId === $project->id
        && $event->projectStageId === $doneStage->id
        && $event->version === 2
        && $event->status === 'done');

    // 3. Standalone CRM task (project_id === null) does NOT broadcast TaskBoardChanged
    Event::fake([TaskBoardChanged::class]);
    $this->actingAs($director)->postJson('/api/internal/tasks', [
        'linkable_type' => 'company',
        'linkable_id' => $company->id,
        'title' => 'Standalone Follow-up',
    ])->assertCreated();
    Event::assertNotDispatched(TaskBoardChanged::class);

    // 4. DeleteTaskAction broadcasts after commit
    Event::fake([TaskBoardChanged::class]);
    $this->actingAs($pm)->deleteJson("/api/internal/tasks/{$taskId}")
        ->assertOk()
        ->assertJsonPath('deleted', true);
    Event::assertDispatchedTimes(TaskBoardChanged::class, 1);
});

it('suppresses TaskBoardChanged on transaction rollback and isolates Reverb exceptions after commit', function () use ($createTaskBroadcastUser): void {
    $dept = Department::query()->create(['name' => 'Delivery', 'slug' => 'tb-bcast-rollback']);
    $pm = $createTaskBroadcastUser($dept, 'EMP-TBR-PM', 'pm.tbr@acme.internal', RoleEnum::ProjectManager);

    $project = Project::query()->create([
        'code' => 'PRJ-2026-6002',
        'owner_id' => $pm->id,
        'department_id' => $dept->id,
        'name' => 'Rollback Board Project',
    ]);
    $todoStage = ProjectStage::query()->create([
        'project_id' => $project->id,
        'name' => 'To Do',
        'category' => ProjectStageCategoryEnum::Todo,
        'sort_order' => 1,
    ]);
    $doingStage = ProjectStage::query()->create([
        'project_id' => $project->id,
        'name' => 'Doing',
        'category' => ProjectStageCategoryEnum::InProgress,
        'sort_order' => 2,
    ]);

    $task = app(CreateTaskAction::class)->execute($pm, new CreateTaskData(
        title: 'Initial Task',
        project_id: (string) $project->id,
        project_stage_id: (string) $todoStage->id,
    ));

    // 1. Rolled-back outer transaction suppresses TaskBoardChanged
    Event::fake([TaskBoardChanged::class]);

    try {
        DB::transaction(function () use ($pm, $task, $doingStage): void {
            app(MoveTaskAction::class)->execute($pm, $task, new MoveTaskData(
                target_stage_id: (string) $doingStage->id,
                expected_version: 1,
            ));

            Event::assertNotDispatched(TaskBoardChanged::class);

            throw new RuntimeException('Abort transaction after move');
        });
    } catch (RuntimeException) {
        // Expected rollback
    }

    Event::assertNotDispatched(TaskBoardChanged::class);
    expect($task->fresh()->project_stage_id)->toBe($todoStage->id)
        ->and($task->fresh()->version)->toBe(1);

    // 2. Reverb outage after commit must NEVER fail HTTP response or DB persistence
    Event::listen(TaskBoardChanged::class, static function (): void {
        throw new RuntimeException('Simulated Reverb offline');
    });

    try {
        $this->actingAs($pm)->patchJson("/api/internal/tasks/{$task->id}/move", [
            'target_stage_id' => $doingStage->id,
            'expected_version' => 1,
        ])->assertOk()
            ->assertJsonPath('project_stage_id', $doingStage->id)
            ->assertJsonPath('version', 2);

        expect($task->fresh()->project_stage_id)->toBe($doingStage->id)
            ->and($task->fresh()->version)->toBe(2);
    } finally {
        Event::forget(TaskBoardChanged::class);
    }
});
