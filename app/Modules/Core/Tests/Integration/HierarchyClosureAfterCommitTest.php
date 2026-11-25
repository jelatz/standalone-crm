<?php

use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Support\Concerns\HasUuidV7;
use App\Support\Silo\HasSiloVisibility;
use App\Support\Silo\VisibleScopeResolver;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

$makeTestRecordModel = static function (): Model {
    $instance = new class extends Model
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
};

$createHierarchyUser = static function (
    Department $department,
    string $code,
    string $name,
    string $email,
    RoleEnum $role,
    ?User $manager = null,
): User {
    $user = User::query()->create([
        'employee_code' => $code,
        'name' => $name,
        'email' => $email,
        'department_id' => $department->id,
        'manager_id' => $manager?->id,
        'status' => UserStatusEnum::Active,
    ]);

    $user->assignRole($role->value);
    app(RebuildUserHierarchyClosureAction::class)->execute($user);

    return $user;
};

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
    Schema::dropIfExists('silo_test_records');
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->truncateTablesForAllConnections();
});

it('immediately invalidates Redis silo cache on commit when a manager subtree is reassigned', function () use ($makeTestRecordModel, $createHierarchyUser): void {
    $dept = Department::query()->create(['name' => 'Commercial', 'slug' => 'commercial-commit']);

    $directorA = $createHierarchyUser($dept, 'EMP-701', 'Director A', 'dir.a.aftercommit@company.internal', RoleEnum::Director);
    $managerA = $createHierarchyUser($dept, 'EMP-702', 'Manager A', 'mgr.a.aftercommit@company.internal', RoleEnum::Sales, $directorA);
    $repA = $createHierarchyUser($dept, 'EMP-703', 'Rep A', 'rep.a.aftercommit@company.internal', RoleEnum::Sales, $managerA);
    $directorB = $createHierarchyUser($dept, 'EMP-704', 'Director B', 'dir.b.aftercommit@company.internal', RoleEnum::Director);

    $recordModel = $makeTestRecordModel();
    $recDirA = $recordModel->newQuery()->create(['owner_id' => $directorA->id, 'title' => 'Dir A Record']);
    $recMgrA = $recordModel->newQuery()->create(['owner_id' => $managerA->id, 'title' => 'Mgr A Record']);
    $recRepA = $recordModel->newQuery()->create(['owner_id' => $repA->id, 'title' => 'Rep A Record']);
    $recDirB = $recordModel->newQuery()->create(['owner_id' => $directorB->id, 'title' => 'Dir B Record']);

    $resolver = app(VisibleScopeResolver::class);

    // Warm Redis cache for both directors
    expect($resolver->for($directorA)->siloUserIds)->toEqualCanonicalizing([$directorA->id, $managerA->id, $repA->id]);
    expect($resolver->for($directorB)->siloUserIds)->toEqualCanonicalizing([$directorB->id]);
    expect(Cache::has(VisibleScopeResolver::cacheKey($directorA->id)))->toBeTrue();
    expect(Cache::has(VisibleScopeResolver::cacheKey($directorB->id)))->toBeTrue();

    // Reassign Manager A subtree from Director A to Director B
    $managerA->manager_id = $directorB->id;
    $managerA->save();
    app(RebuildUserHierarchyClosureAction::class)->execute($managerA);

    expect(Cache::has(VisibleScopeResolver::cacheKey($directorA->id)))->toBeFalse();
    expect(Cache::has(VisibleScopeResolver::cacheKey($directorB->id)))->toBeFalse();

    expect($recordModel->newQuery()->visibleTo($directorA)->pluck('id')->all())
        ->toEqualCanonicalizing([$recDirA->id]);

    expect($recordModel->newQuery()->visibleTo($directorB)->pluck('id')->all())
        ->toEqualCanonicalizing([$recDirB->id, $recMgrA->id, $recRepA->id]);

    expect($resolver->for($directorA)->siloUserIds)->toEqualCanonicalizing([$directorA->id]);
    expect($resolver->for($directorB)->siloUserIds)->toEqualCanonicalizing([$directorB->id, $managerA->id, $repA->id]);
});

it('defers Redis invalidation until outer transaction commits, skips on rollback, and holds PostgreSQL advisory xact lock', function () use ($makeTestRecordModel, $createHierarchyUser): void {
    $dept = Department::query()->create(['name' => 'Enterprise', 'slug' => 'enterprise-xact']);

    $directorA = $createHierarchyUser($dept, 'EMP-801', 'Director A', 'dir.a.xact@company.internal', RoleEnum::Director);
    $managerA = $createHierarchyUser($dept, 'EMP-802', 'Manager A', 'mgr.a.xact@company.internal', RoleEnum::Sales, $directorA);
    $repA = $createHierarchyUser($dept, 'EMP-803', 'Rep A', 'rep.a.xact@company.internal', RoleEnum::Sales, $managerA);
    $directorB = $createHierarchyUser($dept, 'EMP-804', 'Director B', 'dir.b.xact@company.internal', RoleEnum::Director);

    $recordModel = $makeTestRecordModel();
    $recDirA = $recordModel->newQuery()->create(['owner_id' => $directorA->id, 'title' => 'Dir A Record']);
    $recMgrA = $recordModel->newQuery()->create(['owner_id' => $managerA->id, 'title' => 'Mgr A Record']);
    $recRepA = $recordModel->newQuery()->create(['owner_id' => $repA->id, 'title' => 'Rep A Record']);
    $recDirB = $recordModel->newQuery()->create(['owner_id' => $directorB->id, 'title' => 'Dir B Record']);

    $resolver = app(VisibleScopeResolver::class);
    $resolver->for($directorA);
    $resolver->for($directorB);

    // 1. Rolled-back outer transaction must NOT clear cached scopes
    try {
        DB::transaction(function () use ($managerA, $directorB, $directorA): void {
            $managerA->manager_id = $directorB->id;
            $managerA->save();
            app(RebuildUserHierarchyClosureAction::class)->execute($managerA);

            // Cache must remain warm until outer transaction commits
            expect(Cache::has(VisibleScopeResolver::cacheKey($directorA->id)))->toBeTrue();
            expect(Cache::has(VisibleScopeResolver::cacheKey($directorB->id)))->toBeTrue();

            throw new RuntimeException('Abort outer transaction');
        });
    } catch (RuntimeException) {
        // expected rollback
    }

    expect(Cache::has(VisibleScopeResolver::cacheKey($directorA->id)))->toBeTrue();
    expect(Cache::has(VisibleScopeResolver::cacheKey($directorB->id)))->toBeTrue();
    expect($recordModel->newQuery()->visibleTo($directorA)->pluck('id')->all())
        ->toEqualCanonicalizing([$recDirA->id, $recMgrA->id, $recRepA->id]);

    // 2. Committed outer transaction holds pg_advisory_xact_lock until commit and invalidates cache only after commit
    $pgConfig = config('database.connections.pgsql');
    $secondPdo = new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $pgConfig['host'],
            $pgConfig['port'],
            $pgConfig['database'],
        ),
        $pgConfig['username'],
        $pgConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    DB::transaction(function () use ($managerA, $directorB, $directorA, $secondPdo): void {
        $managerA->refresh();
        $managerA->manager_id = $directorB->id;
        $managerA->save();
        app(RebuildUserHierarchyClosureAction::class)->execute($managerA);

        // Cache invalidation is deferred until outer commit
        expect(Cache::has(VisibleScopeResolver::cacheKey($directorA->id)))->toBeTrue();
        expect(Cache::has(VisibleScopeResolver::cacheKey($directorB->id)))->toBeTrue();

        // A concurrent PostgreSQL session must see the transaction advisory lock held
        $lockAcquiredByConcurrentSession = $secondPdo
            ->query("SELECT pg_try_advisory_xact_lock(hashtext('user_hierarchy_closures'))")
            ->fetchColumn();
        expect((bool) $lockAcquiredByConcurrentSession)->toBeFalse();
    });

    // After outer commit, advisory lock is released and Redis caches are invalidated
    $lockReleased = $secondPdo
        ->query("SELECT pg_try_advisory_xact_lock(hashtext('user_hierarchy_closures'))")
        ->fetchColumn();
    expect((bool) $lockReleased)->toBeTrue();

    expect(Cache::has(VisibleScopeResolver::cacheKey($directorA->id)))->toBeFalse();
    expect(Cache::has(VisibleScopeResolver::cacheKey($directorB->id)))->toBeFalse();

    expect($recordModel->newQuery()->visibleTo($directorA)->pluck('id')->all())
        ->toEqualCanonicalizing([$recDirA->id]);
    expect($recordModel->newQuery()->visibleTo($directorB)->pluck('id')->all())
        ->toEqualCanonicalizing([$recDirB->id, $recMgrA->id, $recRepA->id]);
});
