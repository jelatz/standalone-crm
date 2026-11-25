<?php

use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Actions\RevokeEntityCollaboratorAction;
use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Enums\PermissionEnum;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Rbac\Models\EntityCollaborator;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\Team;
use App\Modules\Core\Users\Models\User;
use App\Modules\Core\Users\Models\UserHierarchyClosure;
use App\Support\Concerns\HasUuidV7;
use App\Support\Exceptions\HierarchyCycleException;
use App\Support\Silo\HasSiloVisibility;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function makeSiloTestModel(array $attributes = []): Model
{
    $instance = new class($attributes) extends Model
    {
        use HasSiloVisibility;
        use HasUuidV7;

        protected $table = 'silo_test_records';

        protected $guarded = [];

        public function accountManagerColumn(): ?string
        {
            return 'account_manager_id';
        }
    };

    Relation::morphMap([
        'silo_test_record' => $instance::class,
    ]);

    return $instance;
}

function createOrgUser(
    Department $department,
    string $code,
    string $name,
    string $email,
    RoleEnum $role,
    ?User $manager = null,
    ?Team $team = null,
): User {
    $user = User::query()->create([
        'employee_code' => $code,
        'name' => $name,
        'email' => $email,
        'department_id' => $department->id,
        'primary_team_id' => $team?->id,
        'manager_id' => $manager?->id,
        'status' => UserStatusEnum::Active,
    ]);

    $user->assignRole($role->value);
    app(RebuildUserHierarchyClosureAction::class)->execute($user);

    return $user;
}

beforeEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Schema::dropIfExists('silo_test_records');
    Schema::create('silo_test_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('owner_id');
        $table->uuid('account_manager_id')->nullable();
        $table->string('title');
        $table->timestampsTz();
    });

    $this->seed(RolesPermissionsSeeder::class);
});

afterEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('enforces strict 3-tier silo visibility across sibling reporting trees with account manager and admin bypass', function (): void {
    $dept = Department::query()->create(['name' => 'Sales', 'slug' => 'sales']);
    $teamA = Team::query()->create(['department_id' => $dept->id, 'name' => 'Alpha']);
    $teamB = Team::query()->create(['department_id' => $dept->id, 'name' => 'Beta']);

    $admin = createOrgUser($dept, 'EMP-001', 'Admin User', 'admin@company.internal', RoleEnum::Admin);

    $directorA = createOrgUser($dept, 'EMP-101', 'Director A', 'director.a@company.internal', RoleEnum::Director, null, $teamA);
    $managerA = createOrgUser($dept, 'EMP-102', 'Manager A', 'manager.a@company.internal', RoleEnum::Sales, $directorA, $teamA);
    $repA = createOrgUser($dept, 'EMP-103', 'Rep A', 'rep.a@company.internal', RoleEnum::Sales, $managerA, $teamA);

    $directorB = createOrgUser($dept, 'EMP-201', 'Director B', 'director.b@company.internal', RoleEnum::Director, null, $teamB);
    $managerB = createOrgUser($dept, 'EMP-202', 'Manager B', 'manager.b@company.internal', RoleEnum::Sales, $directorB, $teamB);
    $repB = createOrgUser($dept, 'EMP-203', 'Rep B', 'rep.b@company.internal', RoleEnum::Sales, $managerB, $teamB);

    $recordModel = makeSiloTestModel();

    $recDirA = $recordModel->newQuery()->create(['owner_id' => $directorA->id, 'title' => 'Dir A Deal']);
    $recMgrA = $recordModel->newQuery()->create(['owner_id' => $managerA->id, 'title' => 'Mgr A Deal']);
    $recRepA = $recordModel->newQuery()->create(['owner_id' => $repA->id, 'title' => 'Rep A Deal']);
    $recDirB = $recordModel->newQuery()->create(['owner_id' => $directorB->id, 'title' => 'Dir B Deal']);
    $recMgrB = $recordModel->newQuery()->create(['owner_id' => $managerB->id, 'title' => 'Mgr B Deal']);
    $recRepB = $recordModel->newQuery()->create(['owner_id' => $repB->id, 'title' => 'Rep B Deal']);
    $recAmAssigned = $recordModel->newQuery()->create([
        'owner_id' => $repB->id,
        'account_manager_id' => $repA->id,
        'title' => 'Cross-Silo AM Assigned',
    ]);

    expect(Str::isUuid($directorA->id))->toBeTrue();

    expect($recordModel->newQuery()->visibleTo($repA)->pluck('id')->all())
        ->toEqualCanonicalizing([$recRepA->id, $recAmAssigned->id]);

    expect($recordModel->newQuery()->visibleTo($managerA)->pluck('id')->all())
        ->toEqualCanonicalizing([$recMgrA->id, $recRepA->id, $recAmAssigned->id]);

    expect($recordModel->newQuery()->visibleTo($directorA)->pluck('id')->all())
        ->toEqualCanonicalizing([$recDirA->id, $recMgrA->id, $recRepA->id, $recAmAssigned->id]);

    expect($recordModel->newQuery()->visibleTo($repB)->pluck('id')->all())
        ->toEqualCanonicalizing([$recRepB->id, $recAmAssigned->id]);

    expect($recordModel->newQuery()->visibleTo($admin)->pluck('id')->all())
        ->toHaveCount(7);
});

it('prevents reporting hierarchy cycles and leaves closure table intact', function (): void {
    $dept = Department::query()->create(['name' => 'Sales', 'slug' => 'sales-cycle']);

    $directorA = createOrgUser($dept, 'EMP-301', 'Director A', 'dir.cycle@company.internal', RoleEnum::Director);
    $managerA = createOrgUser($dept, 'EMP-302', 'Manager A', 'mgr.cycle@company.internal', RoleEnum::Sales, $directorA);
    $repA = createOrgUser($dept, 'EMP-303', 'Rep A', 'rep.cycle@company.internal', RoleEnum::Sales, $managerA);

    $beforeClosures = UserHierarchyClosure::query()
        ->orderBy('ancestor_id')
        ->orderBy('descendant_id')
        ->get(['ancestor_id', 'descendant_id', 'depth'])
        ->toArray();

    // Self-manager cycle
    $repA->manager_id = $repA->id;
    expect(fn () => app(RebuildUserHierarchyClosureAction::class)->execute($repA))
        ->toThrow(HierarchyCycleException::class);

    // Direct report cycle
    $directorA->manager_id = $managerA->id;
    expect(fn () => app(RebuildUserHierarchyClosureAction::class)->execute($directorA))
        ->toThrow(HierarchyCycleException::class);

    // Transitive descendant cycle
    $directorA->manager_id = $repA->id;
    expect(fn () => app(RebuildUserHierarchyClosureAction::class)->execute($directorA))
        ->toThrow(HierarchyCycleException::class);

    $afterClosures = UserHierarchyClosure::query()
        ->orderBy('ancestor_id')
        ->orderBy('descendant_id')
        ->get(['ancestor_id', 'descendant_id', 'depth'])
        ->toArray();

    expect($afterClosures)->toBe($beforeClosures);
    expect($directorA->fresh()->manager_id)->toBeNull();
});

it('grants and revokes explicit cross-silo access via entity_collaborators', function (): void {
    $dept = Department::query()->create(['name' => 'Revenue', 'slug' => 'revenue']);

    $directorA = createOrgUser($dept, 'EMP-401', 'Director A', 'dir.a.collab@company.internal', RoleEnum::Director);
    $managerA = createOrgUser($dept, 'EMP-402', 'Manager A', 'mgr.a.collab@company.internal', RoleEnum::Sales, $directorA);
    $repA = createOrgUser($dept, 'EMP-403', 'Rep A', 'rep.a.collab@company.internal', RoleEnum::Sales, $managerA);
    $repB = createOrgUser($dept, 'EMP-404', 'Rep B', 'rep.b.collab@company.internal', RoleEnum::Sales);

    $recordModel = makeSiloTestModel();
    $recMgrA = $recordModel->newQuery()->create(['owner_id' => $managerA->id, 'title' => 'Mgr A Secret']);
    $recRepA = $recordModel->newQuery()->create(['owner_id' => $repA->id, 'title' => 'Rep A Shared']);
    $recRepB = $recordModel->newQuery()->create(['owner_id' => $repB->id, 'title' => 'Rep B Own']);

    expect($recordModel->newQuery()->visibleTo($repB)->pluck('id')->all())
        ->toEqualCanonicalizing([$recRepB->id]);

    $collaborator = app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $recRepA,
        user: $repB,
        accessLevel: CollaboratorAccessLevel::Viewer,
        roleLabel: CollaboratorRoleLabel::SalesOverlay,
        grantedBy: $directorA,
    );

    expect($collaborator)->toBeInstanceOf(EntityCollaborator::class)
        ->and($collaborator->collaboratable_type)->toBe('silo_test_record')
        ->and($collaborator->collaboratable_id)->toBe($recRepA->id);

    // Rep B sees only Rep B own + explicitly shared Rep A record (never Mgr A secret)
    expect($recordModel->newQuery()->visibleTo($repB)->pluck('id')->all())
        ->toEqualCanonicalizing([$recRepB->id, $recRepA->id]);

    // Idempotent re-grant upgrades access level without duplicate key error
    $updatedCollaborator = app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $recRepA,
        user: $repB,
        accessLevel: CollaboratorAccessLevel::Editor,
        roleLabel: CollaboratorRoleLabel::AccountManager,
        grantedBy: $directorA,
    );
    expect($updatedCollaborator->id)->toBe($collaborator->id)
        ->and($updatedCollaborator->access_level)->toBe(CollaboratorAccessLevel::Editor)
        ->and($updatedCollaborator->role_label)->toBe(CollaboratorRoleLabel::AccountManager);

    app(RevokeEntityCollaboratorAction::class)->execute($recRepA, $repB);

    expect($recordModel->newQuery()->visibleTo($repB)->pluck('id')->all())
        ->toEqualCanonicalizing([$recRepB->id]);
});

it('rebuilds closure tree on manager reassignment and preserves committed DB manager when model is not dirty', function (): void {
    $dept = Department::query()->create(['name' => 'Commercial', 'slug' => 'commercial']);

    $directorA = createOrgUser($dept, 'EMP-501', 'Director A', 'dir.a.tree@company.internal', RoleEnum::Director);
    $managerA = createOrgUser($dept, 'EMP-502', 'Manager A', 'mgr.a.tree@company.internal', RoleEnum::Sales, $directorA);
    $repA = createOrgUser($dept, 'EMP-503', 'Rep A', 'rep.a.tree@company.internal', RoleEnum::Sales, $managerA);
    $directorB = createOrgUser($dept, 'EMP-504', 'Director B', 'dir.b.tree@company.internal', RoleEnum::Director);

    $recordModel = makeSiloTestModel();
    $recDirA = $recordModel->newQuery()->create(['owner_id' => $directorA->id, 'title' => 'Dir A Record']);
    $recMgrA = $recordModel->newQuery()->create(['owner_id' => $managerA->id, 'title' => 'Mgr A Record']);
    $recRepA = $recordModel->newQuery()->create(['owner_id' => $repA->id, 'title' => 'Rep A Record']);
    $recDirB = $recordModel->newQuery()->create(['owner_id' => $directorB->id, 'title' => 'Dir B Record']);

    // Simulate a stale in-memory model loaded before a concurrent manager_id update committed
    $staleManagerA = User::query()->findOrFail($managerA->id);
    expect($staleManagerA->manager_id)->toBe($directorA->id);

    DB::table('users')->where('id', $managerA->id)->update(['manager_id' => $directorB->id]);

    // Executing with stale non-dirty model must re-read DB manager_id instead of overwriting it
    app(RebuildUserHierarchyClosureAction::class)->execute($staleManagerA);

    expect($staleManagerA->manager_id)->toBe($directorB->id)
        ->and($managerA->fresh()->manager_id)->toBe($directorB->id);

    expect($recordModel->newQuery()->visibleTo($directorA)->pluck('id')->all())
        ->toEqualCanonicalizing([$recDirA->id]);

    expect($recordModel->newQuery()->visibleTo($directorB)->pluck('id')->all())
        ->toEqualCanonicalizing([$recDirB->id, $recMgrA->id, $recRepA->id]);
});

it('seeds all 8 RBAC roles and permissions idempotently and enforces PostgreSQL citext email uniqueness', function (): void {
    // Seed a second time to verify idempotency
    $this->seed(RolesPermissionsSeeder::class);

    $seededRoleNames = Role::query()->where('guard_name', 'web')->pluck('name')->all();
    $expectedRoleNames = array_map(static fn (RoleEnum $role): string => $role->value, RoleEnum::cases());
    expect($seededRoleNames)->toEqualCanonicalizing($expectedRoleNames);

    $seededPermNames = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
    $expectedPermNames = array_map(static fn (PermissionEnum $perm): string => $perm->value, PermissionEnum::cases());
    expect($seededPermNames)->toEqualCanonicalizing($expectedPermNames);

    $opsManagerRole = Role::findByName(RoleEnum::OperationsManager->value, 'web');
    expect($opsManagerRole->hasPermissionTo(PermissionEnum::HandoffsReview->value))->toBeTrue()
        ->and($opsManagerRole->hasPermissionTo(PermissionEnum::HandoffsApprove->value))->toBeTrue()
        ->and($opsManagerRole->hasPermissionTo(PermissionEnum::ProjectsManageTemplates->value))->toBeTrue()
        ->and($opsManagerRole->hasPermissionTo(PermissionEnum::AdminUsers->value))->toBeFalse();

    $salesRole = Role::findByName(RoleEnum::Sales->value, 'web');
    expect($salesRole->hasPermissionTo(PermissionEnum::DealsMoveStage->value))->toBeTrue()
        ->and($salesRole->hasPermissionTo(PermissionEnum::HandoffsSubmit->value))->toBeTrue()
        ->and($salesRole->hasPermissionTo(PermissionEnum::HandoffsApprove->value))->toBeFalse()
        ->and($salesRole->hasPermissionTo(PermissionEnum::DealsOverrideGates->value))->toBeFalse();

    $itRole = Role::findByName(RoleEnum::It->value, 'web');
    expect($itRole->hasPermissionTo(PermissionEnum::DealsView->value))->toBeFalse()
        ->and($itRole->hasPermissionTo(PermissionEnum::AdminSystemHealth->value))->toBeTrue();

    // Case-insensitive PostgreSQL citext email uniqueness & lookup
    $dept = Department::query()->create(['name' => 'Engineering', 'slug' => 'engineering']);
    createOrgUser($dept, 'EMP-601', 'Case User', 'Jane.Doe@Company.Internal', RoleEnum::It);

    expect(User::query()->where('email', 'jane.doe@company.internal')->exists())->toBeTrue();

    expect(fn () => DB::transaction(static fn () => User::query()->create([
        'employee_code' => 'EMP-602',
        'name' => 'Duplicate Case User',
        'email' => 'jane.doe@company.internal',
        'department_id' => $dept->id,
    ])))->toThrow(QueryException::class);
});
