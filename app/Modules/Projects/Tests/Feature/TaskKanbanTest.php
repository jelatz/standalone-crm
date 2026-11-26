<?php

use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Timeline\Enums\ActivityTypeEnum;
use App\Modules\Crm\Timeline\Models\Activity;
use App\Modules\Projects\Projects\Enums\ProjectStageCategoryEnum;
use App\Modules\Projects\Projects\Models\Project;
use App\Modules\Projects\Projects\Models\ProjectStage;
use App\Modules\Projects\Tasks\Enums\TaskStatusEnum;
use App\Modules\Projects\Tasks\Models\Task;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function createTaskKanbanUser(
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
}

beforeEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesPermissionsSeeder::class);
});

afterEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('handles MoveTaskAction fractional ordering, automatic column rebalance, and stage-then-task lock order', function (): void {
    $dept = Department::query()->create(['name' => 'Delivery', 'slug' => 'tk-kanban-ord']);
    $pmA = createTaskKanbanUser($dept, 'EMP-TK-PMA', 'pm.a.kb@acme.internal', RoleEnum::ProjectManager);
    $pmB = createTaskKanbanUser($dept, 'EMP-TK-PMB', 'pm.b.kb@acme.internal', RoleEnum::ProjectManager);

    $project = Project::query()->create([
        'code' => 'PRJ-2026-7001',
        'owner_id' => $pmA->id,
        'department_id' => $dept->id,
        'name' => 'Kanban Ordering Project',
    ]);
    $todoStage = ProjectStage::query()->create([
        'project_id' => $project->id,
        'name' => 'To Do',
        'category' => ProjectStageCategoryEnum::Todo,
        'sort_order' => 1,
    ]);
    $inProgressStage = ProjectStage::query()->create([
        'project_id' => $project->id,
        'name' => 'In Progress',
        'category' => ProjectStageCategoryEnum::InProgress,
        'sort_order' => 2,
    ]);

    $otherProject = Project::query()->create([
        'code' => 'PRJ-2026-7002',
        'owner_id' => $pmB->id,
        'department_id' => $dept->id,
        'name' => 'Other Project',
    ]);
    $otherStage = ProjectStage::query()->create([
        'project_id' => $otherProject->id,
        'name' => 'To Do',
        'category' => ProjectStageCategoryEnum::Todo,
        'sort_order' => 1,
    ]);
    $otherTask = Task::query()->create([
        'project_id' => $otherProject->id,
        'project_stage_id' => $otherStage->id,
        'creator_id' => $pmB->id,
        'title' => 'Other Project Task',
    ]);

    $t1 = $this->actingAs($pmA)->postJson('/api/internal/tasks', [
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'title' => 'Task One',
    ])->assertCreated()->json();

    $t2 = $this->actingAs($pmA)->postJson('/api/internal/tasks', [
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'title' => 'Task Two',
    ])->assertCreated()->json();

    $t3 = $this->actingAs($pmA)->postJson('/api/internal/tasks', [
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'title' => 'Task Three',
    ])->assertCreated()->json();

    expect((float) $t1['board_position'])->toBe(65536.0)
        ->and((float) $t2['board_position'])->toBe(131072.0)
        ->and((float) $t3['board_position'])->toBe(196608.0);

    // Record lock order on MoveTaskAction: ProjectStage FOR UPDATE MUST happen before Task FOR UPDATE
    $lockSqls = [];
    DB::listen(static function (QueryExecuted $query) use (&$lockSqls): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update')) {
            $lockSqls[] = $sql;
        }
    });

    // Move T3 between T1 and T2 -> midpoint (65536 + 131072) / 2 = 98304
    $moveMid = $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$t3['id']}/move", [
        'target_stage_id' => $todoStage->id,
        'expected_version' => 1,
        'before_task_id' => $t1['id'],
        'after_task_id' => $t2['id'],
    ])->assertOk();

    expect((float) $moveMid->json('board_position'))->toBe(98304.0)
        ->and($moveMid->json('version'))->toBe(2)
        ->and($lockSqls[0] ?? '')->toContain('"project_stages"')
        ->and($lockSqls[1] ?? '')->toContain('"tasks"');

    // Move T2 to top before T1 -> midpoint (0 + 65536) / 2 = 32768
    $moveTop = $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$t2['id']}/move", [
        'target_stage_id' => $todoStage->id,
        'expected_version' => 1,
        'before_task_id' => null,
        'after_task_id' => $t1['id'],
    ])->assertOk();
    expect((float) $moveTop->json('board_position'))->toBe(32768.0);

    // Workspace Inertia Kanban renders ordered [T2, T1, T3]
    $this->actingAs($pmA)->get("/app/projects/{$project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/pages/ProjectWorkspacePage')
            ->where('columns.0.tasks_count', 3)
            ->where('columns.0.tasks.0.id', $t2['id'])
            ->where('columns.0.tasks.1.id', $t1['id'])
            ->where('columns.0.tasks.2.id', $t3['id']));

    // Rebalance trigger: two adjacent tasks in In Progress with gap < 0.0001
    $tightA = Task::query()->create([
        'project_id' => $project->id,
        'project_stage_id' => $inProgressStage->id,
        'creator_id' => $pmA->id,
        'title' => 'Tight A',
        'board_position' => 100.0,
    ]);
    $tightB = Task::query()->create([
        'project_id' => $project->id,
        'project_stage_id' => $inProgressStage->id,
        'creator_id' => $pmA->id,
        'title' => 'Tight B',
        'board_position' => 100.00005,
    ]);

    $rebalancedMove = $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$t3['id']}/move", [
        'target_stage_id' => $inProgressStage->id,
        'expected_version' => 2,
        'before_task_id' => $tightA->id,
        'after_task_id' => $tightB->id,
    ])->assertOk();

    expect((float) $tightA->fresh()->board_position)->toBe(65536.0)
        ->and((float) $tightB->fresh()->board_position)->toBe(131072.0)
        ->and((float) $rebalancedMove->json('board_position'))->toBe(98304.0)
        ->and($rebalancedMove->json('project_stage_id'))->toBe($inProgressStage->id)
        ->and($rebalancedMove->json('version'))->toBe(3);

    // Cross-project stage or neighbor rejected with 404
    $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$t1['id']}/move", [
        'target_stage_id' => $otherStage->id,
        'expected_version' => 1,
    ])->assertNotFound();

    $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$t1['id']}/move", [
        'target_stage_id' => $inProgressStage->id,
        'expected_version' => 1,
        'before_task_id' => $otherTask->id,
    ])->assertNotFound();
});

it('enforces 409 Conflict on stale version, 422 on full wip_limit, and done completed_at + TaskCompleted transitions', function (): void {
    $dept = Department::query()->create(['name' => 'Delivery', 'slug' => 'tk-kanban-wip']);
    $pmA = createTaskKanbanUser($dept, 'EMP-TK-W-PMA', 'pm.w.kb@acme.internal', RoleEnum::ProjectManager);

    $project = Project::query()->create([
        'code' => 'PRJ-2026-7003',
        'owner_id' => $pmA->id,
        'department_id' => $dept->id,
        'name' => 'WIP & Lock Project',
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
        'wip_limit' => 1,
    ]);
    $doneStage = ProjectStage::query()->create([
        'project_id' => $project->id,
        'name' => 'Done',
        'category' => ProjectStageCategoryEnum::Done,
        'sort_order' => 3,
    ]);

    $t1 = Task::query()->create([
        'project_id' => $project->id,
        'project_stage_id' => $todoStage->id,
        'creator_id' => $pmA->id,
        'title' => 'First Task',
        'board_position' => 65536.0,
        'version' => 1,
    ]);
    $t2 = Task::query()->create([
        'project_id' => $project->id,
        'project_stage_id' => $doingStage->id,
        'creator_id' => $pmA->id,
        'title' => 'Occupying WIP Slot',
        'board_position' => 65536.0,
        'version' => 1,
    ]);

    // 1. WIP limit = 1 is full -> moving $t1 into $doingStage fails with 422
    $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$t1->id}/move", [
        'target_stage_id' => $doingStage->id,
        'expected_version' => 1,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['target_stage_id']);

    // Reordering $t2 within $doingStage when count == wip_limit is allowed!
    $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$t2->id}/move", [
        'target_stage_id' => $doingStage->id,
        'expected_version' => 1,
    ])->assertOk()
        ->assertJsonPath('version', 2);

    // 2. Stale expected_version returns 409 Conflict with current_version & fresh entity payload in MoveTaskAction & UpdateTaskAction
    $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$t2->id}/move", [
        'target_stage_id' => $todoStage->id,
        'expected_version' => 1, // stale! current is 2
    ])->assertConflict()
        ->assertJsonPath('current_version', 2)
        ->assertJsonPath('entity.id', $t2->id)
        ->assertJsonPath('entity.version', 2);

    $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$t2->id}", [
        'expected_version' => 1,
        'title' => 'Stale Update',
    ])->assertConflict()
        ->assertJsonPath('current_version', 2)
        ->assertJsonPath('entity.version', 2);

    // 3. Moving $t1 to $doneStage sets status=done, completed_at, and records TaskCompleted activity
    $doneRes = $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$t1->id}/move", [
        'target_stage_id' => $doneStage->id,
        'expected_version' => 1,
    ])->assertOk()
        ->assertJsonPath('status', TaskStatusEnum::Done->value)
        ->assertJsonPath('version', 2);

    expect($doneRes->json('completed_at'))->not->toBeNull()
        ->and($t1->fresh()->completed_at)->not->toBeNull()
        ->and(Activity::query()
            ->where('source_type', 'task')
            ->where('source_id', $t1->id)
            ->where('activity_type', ActivityTypeEnum::TaskCompleted->value)
            ->count())->toBe(1);

    // Reordering inside $doneStage does NOT duplicate TaskCompleted
    $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$t1->id}/move", [
        'target_stage_id' => $doneStage->id,
        'expected_version' => 2,
    ])->assertOk()
        ->assertJsonPath('version', 3);

    expect(Activity::query()
        ->where('source_type', 'task')
        ->where('source_id', $t1->id)
        ->where('activity_type', ActivityTypeEnum::TaskCompleted->value)
        ->count())->toBe(1);

    // Moving $t1 out of $doneStage back to $todoStage clears completed_at and resets status from done
    $reopenRes = $this->actingAs($pmA)->patchJson("/api/internal/tasks/{$t1->id}/move", [
        'target_stage_id' => $todoStage->id,
        'expected_version' => 3,
    ])->assertOk()
        ->assertJsonPath('status', TaskStatusEnum::Todo->value)
        ->assertJsonPath('completed_at', null)
        ->assertJsonPath('version', 4);

    expect($t1->fresh()->completed_at)->toBeNull();
    unset($reopenRes);
});
