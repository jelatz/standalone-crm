<?php

use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Actions\RebuildUserHierarchyClosureAction;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Crm\Companies\Models\Company;
use App\Modules\Crm\Deals\Actions\BroadcastDealBoardInvalidationAction;
use App\Modules\Crm\Deals\Actions\MoveDealStageAction;
use App\Modules\Crm\Deals\Data\MoveDealStageData;
use App\Modules\Crm\Deals\Events\SiloBoardInvalidated;
use App\Modules\Crm\Deals\Models\Deal;
use App\Modules\Crm\Pipelines\Models\Pipeline;
use Database\Seeders\CrmDefaultsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

$createBroadcastTestUser = static function (
    Department $department,
    string $code,
    string $email,
    RoleEnum $role,
    ?User $manager = null,
    UserStatusEnum $status = UserStatusEnum::Active,
): User {
    $user = User::query()->create([
        'employee_code' => $code,
        'name' => $code,
        'email' => $email,
        'department_id' => $department->id,
        'manager_id' => $manager?->id,
        'status' => $status,
    ]);

    $user->assignRole($role->value);
    app(RebuildUserHierarchyClosureAction::class)->execute($user);

    return $user;
};

beforeEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(CrmDefaultsSeeder::class);
});

afterEach(function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->truncateTablesForAllConnections();
});

it('dispatches ID-only SiloBoardInvalidated on private-user.{id} ONLY after commit to active deals.view recipients in Silo A and NEVER to Silo B', function () use ($createBroadcastTestUser): void {
    $dept = Department::query()->create(['name' => 'Commercial', 'slug' => 't14-bcast-comm']);

    $suspendedTop = $createBroadcastTestUser($dept, 'EMP-BC-SUSPTOP', 'susptop.bc@acme.internal', RoleEnum::Director, null, UserStatusEnum::Suspended);
    $directorA = $createBroadcastTestUser($dept, 'EMP-BC-DIRA', 'dir.a.bc@acme.internal', RoleEnum::Director, $suspendedTop);
    $managerA = $createBroadcastTestUser($dept, 'EMP-BC-MGRA', 'mgr.a.bc@acme.internal', RoleEnum::Sales, $directorA);
    $repA = $createBroadcastTestUser($dept, 'EMP-BC-REPA', 'rep.a.bc@acme.internal', RoleEnum::Sales, $managerA);
    $amUser = $createBroadcastTestUser($dept, 'EMP-BC-AM', 'am.bc@acme.internal', RoleEnum::AccountManager);
    $collabSales = $createBroadcastTestUser($dept, 'EMP-BC-COL', 'collab.bc@acme.internal', RoleEnum::Sales);
    $collabIt = $createBroadcastTestUser($dept, 'EMP-BC-IT', 'it.bc@acme.internal', RoleEnum::It);
    $trashedCollab = $createBroadcastTestUser($dept, 'EMP-BC-TRASH', 'trash.bc@acme.internal', RoleEnum::Sales);

    $directorB = $createBroadcastTestUser($dept, 'EMP-BC-DIRB', 'dir.b.bc@acme.internal', RoleEnum::Director);
    $repB = $createBroadcastTestUser($dept, 'EMP-BC-REPB', 'rep.b.bc@acme.internal', RoleEnum::Sales, $directorB);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stages = $pipeline->stages()->orderBy('sort_order')->get()->keyBy('name');

    $companyA = Company::query()->create(['name' => 'Confidential Silo A Client', 'owner_id' => $repA->id]);

    $deal = Deal::query()->create([
        'company_id' => $companyA->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stages['Qualification']->id,
        'owner_id' => $repA->id,
        'account_manager_id' => $amUser->id,
        'title' => 'Top Secret Merger Deal',
        'amount_cents' => 9900000,
        'stage_entered_at' => now(),
        'version' => 1,
    ]);

    $grantAction = app(GrantEntityCollaboratorAction::class);
    $grantAction->execute($deal, $collabSales, CollaboratorAccessLevel::Viewer, CollaboratorRoleLabel::SalesOverlay, $repA);
    $grantAction->execute($deal, $collabIt, CollaboratorAccessLevel::Manager, CollaboratorRoleLabel::ItLead, $repA);
    $grantAction->execute($deal, $trashedCollab, CollaboratorAccessLevel::Viewer, CollaboratorRoleLabel::Observer, $repA);
    // Also add $directorA as collaborator to verify deduplication
    $grantAction->execute($deal, $directorA, CollaboratorAccessLevel::Manager, CollaboratorRoleLabel::Observer, $repA);

    $trashedCollab->delete();

    Event::fake([SiloBoardInvalidated::class]);

    $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$deal->id}/move-stage", [
        'target_stage_id' => $stages['Discovery']->id,
        'expected_version' => 1,
    ])->assertOk()
        ->assertJsonPath('stage_id', $stages['Discovery']->id)
        ->assertJsonPath('version', 2);

    $dispatched = Event::dispatched(SiloBoardInvalidated::class);
    $recipientIds = $dispatched
        ->map(static fn (array $entry): string => $entry[0]->recipientUserId)
        ->all();

    expect($recipientIds)->toEqualCanonicalizing([
        $repA->id,
        $managerA->id,
        $directorA->id,
        $amUser->id,
        $collabSales->id,
    ])
        ->and($recipientIds)->not->toContain($directorB->id, $repB->id, $collabIt->id, $suspendedTop->id, $trashedCollab->id);

    foreach ($dispatched as [$event]) {
        expect($event)->toBeInstanceOf(SiloBoardInvalidated::class)
            ->and($event->broadcastOn())->toHaveCount(1)
            ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
            ->and($event->broadcastOn()[0]->name)->toBe("private-user.{$event->recipientUserId}")
            ->and($event->broadcastWith())->toBe([
                'entity_type' => 'deal',
                'entity_id' => $deal->id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stages['Discovery']->id,
                'board_position' => 65536.0,
                'version' => 2,
            ])
            ->and(array_keys($event->broadcastWith()))->not->toContain('title', 'amount_cents', 'company_name');
    }
});

it('suppresses SiloBoardInvalidated broadcast on outer transaction rollback and isolates Reverb exceptions after commit', function () use ($createBroadcastTestUser): void {
    $dept = Department::query()->create(['name' => 'Enterprise', 'slug' => 't14-bcast-rollback']);
    $repA = $createBroadcastTestUser($dept, 'EMP-RB-REPA', 'rep.rb.bc@acme.internal', RoleEnum::Sales);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stages = $pipeline->stages()->orderBy('sort_order')->get()->keyBy('name');

    $company = Company::query()->create(['name' => 'Rollback Co', 'owner_id' => $repA->id]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stages['Qualification']->id,
        'owner_id' => $repA->id,
        'title' => 'Rollback Deal',
        'amount_cents' => 50000,
        'stage_entered_at' => now(),
        'version' => 1,
    ]);

    // 1. Rolled-back outer transaction must NOT dispatch SiloBoardInvalidated
    Event::fake([SiloBoardInvalidated::class]);

    try {
        DB::transaction(function () use ($repA, $deal, $stages): void {
            app(MoveDealStageAction::class)->execute(
                $repA,
                $deal,
                new MoveDealStageData(
                    target_stage_id: (string) $stages['Discovery']->id,
                    expected_version: 1,
                ),
            );

            // Event must NOT be dispatched while transaction is still uncommitted
            Event::assertNotDispatched(SiloBoardInvalidated::class);

            throw new RuntimeException('Abort transaction after move');
        });
    } catch (RuntimeException) {
        // Expected rollback
    }

    Event::assertNotDispatched(SiloBoardInvalidated::class);
    expect($deal->fresh()->stage_id)->toBe($stages['Qualification']->id)
        ->and($deal->fresh()->version)->toBe(1);

    // 2. Reverb/broadcasting failure inside listener after commit must NEVER fail HTTP response
    Event::listen(SiloBoardInvalidated::class, static function (): void {
        throw new RuntimeException('Simulated Reverb outage');
    });

    try {
        $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$deal->id}/move-stage", [
            'target_stage_id' => $stages['Discovery']->id,
            'expected_version' => 1,
        ])->assertOk()
            ->assertJsonPath('stage_id', $stages['Discovery']->id)
            ->assertJsonPath('version', 2);

        expect($deal->fresh()->stage_id)->toBe($stages['Discovery']->id)
            ->and($deal->fresh()->version)->toBe(2);
    } finally {
        Event::forget(SiloBoardInvalidated::class);
    }

    // 3. DeleteDealAction also dispatches SiloBoardInvalidated after commit to authorized recipients
    Event::fake([SiloBoardInvalidated::class]);

    $this->actingAs($repA)->deleteJson("/api/internal/crm/deals/{$deal->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    Event::assertDispatchedTimes(SiloBoardInvalidated::class, 1);
    Event::assertDispatched(
        SiloBoardInvalidated::class,
        static fn (SiloBoardInvalidated $event): bool => $event->recipientUserId === $repA->id
            && $event->dealId === $deal->id,
    );
});

it('isolates transient recipient resolution DB exceptions after commit so HTTP responses succeed', function () use ($createBroadcastTestUser): void {
    $dept = Department::query()->create(['name' => 'Resilience', 'slug' => 't14-bcast-resilience']);
    $repA = $createBroadcastTestUser($dept, 'EMP-RES-REPA', 'rep.res.bc@acme.internal', RoleEnum::Sales);

    $pipeline = Pipeline::query()->where('slug', 'new-business')->firstOrFail();
    $stages = $pipeline->stages()->orderBy('sort_order')->get()->keyBy('name');

    $company = Company::query()->create(['name' => 'Resilient Co', 'owner_id' => $repA->id]);
    $deal = Deal::query()->create([
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stages['Qualification']->id,
        'owner_id' => $repA->id,
        'title' => 'Resilient Deal',
        'amount_cents' => 50000,
        'stage_entered_at' => now(),
        'version' => 1,
    ]);

    $failingBroadcaster = new class extends BroadcastDealBoardInvalidationAction
    {
        public function resolveAuthorizedRecipientIds(string $dealId, string $ownerId, ?string $accountManagerId): array
        {
            throw new RuntimeException('Simulated post-commit recipient query failure');
        }
    };

    $this->app->instance(BroadcastDealBoardInvalidationAction::class, $failingBroadcaster);

    try {
        $this->actingAs($repA)->patchJson("/api/internal/crm/deals/{$deal->id}/move-stage", [
            'target_stage_id' => $stages['Discovery']->id,
            'expected_version' => 1,
        ])->assertOk()
            ->assertJsonPath('stage_id', $stages['Discovery']->id)
            ->assertJsonPath('version', 2);

        expect($deal->fresh()->stage_id)->toBe($stages['Discovery']->id)
            ->and($deal->fresh()->version)->toBe(2);
    } finally {
        $this->app->forgetInstance(BroadcastDealBoardInvalidationAction::class);
    }
});
