<?php

use App\Modules\Core\Rbac\Actions\GrantEntityCollaboratorAction;
use App\Modules\Core\Rbac\Enums\CollaboratorAccessLevel;
use App\Modules\Core\Rbac\Enums\CollaboratorRoleLabel;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use App\Modules\Projects\Projects\Models\Project;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

it('authorizes private-user.{selfId} on /broadcasting/auth and rejects private-user.{otherId} with 403', function (): void {
    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb', [
        'driver' => 'reverb',
        'key' => 'test-reverb-key',
        'secret' => 'test-reverb-secret',
        'app_id' => 'test-reverb-app',
        'options' => [
            'host' => '127.0.0.1',
            'port' => 8080,
            'scheme' => 'http',
            'useTLS' => false,
        ],
    ]);
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');

    $dept = Department::query()->create(['name' => 'Engineering', 'slug' => 'eng-reverb']);

    $userA = User::query()->create([
        'employee_code' => 'EMP-REV-A',
        'name' => 'User A',
        'email' => 'usera@acme.com',
        'department_id' => $dept->id,
        'status' => UserStatusEnum::Active,
    ]);

    $userB = User::query()->create([
        'employee_code' => 'EMP-REV-B',
        'name' => 'User B',
        'email' => 'userb@acme.com',
        'department_id' => $dept->id,
        'status' => UserStatusEnum::Active,
    ]);

    $this->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-user.{$userA->id}",
    ])->assertUnauthorized();

    $this->actingAs($userA)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-user.{$userA->id}",
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);

    $this->actingAs($userA)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-user.{$userB->id}",
        ])
        ->assertForbidden();
});

it('authorizes presence-project-board.{projectId} strictly via ProjectPolicy::view with Superadmin/Admin parity', function (): void {
    Cache::flush();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesPermissionsSeeder::class);

    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb', [
        'driver' => 'reverb',
        'key' => 'test-reverb-key',
        'secret' => 'test-reverb-secret',
        'app_id' => 'test-reverb-app',
        'options' => [
            'host' => '127.0.0.1',
            'port' => 8080,
            'scheme' => 'http',
            'useTLS' => false,
        ],
    ]);
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');

    $dept = Department::query()->create(['name' => 'Delivery', 'slug' => 'deliv-reverb']);

    $pmOwner = User::query()->create([
        'employee_code' => 'EMP-PREV-PM1',
        'name' => 'PM Owner',
        'email' => 'pm1.rev@acme.com',
        'department_id' => $dept->id,
        'status' => UserStatusEnum::Active,
    ]);
    $pmOwner->assignRole(RoleEnum::ProjectManager->value);

    $pmCrossSilo = User::query()->create([
        'employee_code' => 'EMP-PREV-PM2',
        'name' => 'PM Other',
        'email' => 'pm2.rev@acme.com',
        'department_id' => $dept->id,
        'status' => UserStatusEnum::Active,
    ]);
    $pmCrossSilo->assignRole(RoleEnum::ProjectManager->value);

    $amAssigned = User::query()->create([
        'employee_code' => 'EMP-PREV-AM',
        'name' => 'AM Assigned',
        'email' => 'am.rev@acme.com',
        'department_id' => $dept->id,
        'status' => UserStatusEnum::Active,
    ]);
    $amAssigned->assignRole(RoleEnum::AccountManager->value);

    $admin = User::query()->create([
        'employee_code' => 'EMP-PREV-ADM',
        'name' => 'Admin User',
        'email' => 'adm.rev@acme.com',
        'department_id' => $dept->id,
        'status' => UserStatusEnum::Active,
    ]);
    $admin->assignRole(RoleEnum::Admin->value);

    $superadmin = User::query()->create([
        'employee_code' => 'EMP-PREV-SA',
        'name' => 'Superadmin User',
        'email' => 'sa.rev@acme.com',
        'department_id' => $dept->id,
        'status' => UserStatusEnum::Active,
    ]);
    $superadmin->assignRole(RoleEnum::Superadmin->value);

    $project = Project::query()->create([
        'code' => 'PRJ-2026-9001',
        'owner_id' => $pmOwner->id,
        'account_manager_id' => $amAssigned->id,
        'department_id' => $dept->id,
        'name' => 'Presence Board Project',
    ]);

    // Unauthenticated rejected
    $this->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "presence-project-board.{$project->id}",
    ])->assertUnauthorized();

    // Non-UUID or nonexistent project fails closed (403)
    $this->actingAs($admin)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'presence-project-board.not-a-uuid',
        ])
        ->assertForbidden();

    $missingUuid = (string) Str::uuid7();
    $this->actingAs($admin)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-project-board.{$missingUuid}",
        ])
        ->assertForbidden();

    // Owner, assigned AM, Admin, and Superadmin succeed with presence user info
    foreach ([$pmOwner, $amAssigned, $admin, $superadmin] as $authorized) {
        $res = $this->actingAs($authorized)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "presence-project-board.{$project->id}",
            ])
            ->assertOk()
            ->assertJsonStructure(['auth', 'channel_data']);

        $channelData = json_decode((string) $res->json('channel_data'), true);
        expect($channelData['user_id'] ?? null)->toBe((string) $authorized->id)
            ->and($channelData['user_info']['id'] ?? null)->toBe((string) $authorized->id)
            ->and($channelData['user_info']['name'] ?? null)->toBe($authorized->name);
    }

    // Cross-silo PM rejected until granted collaborator access
    $this->actingAs($pmCrossSilo)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-project-board.{$project->id}",
        ])
        ->assertForbidden();

    app(GrantEntityCollaboratorAction::class)->execute(
        collaboratable: $project,
        user: $pmCrossSilo,
        accessLevel: CollaboratorAccessLevel::Viewer,
        roleLabel: CollaboratorRoleLabel::Observer,
        grantedBy: $pmOwner,
    );

    $this->actingAs($pmCrossSilo)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-project-board.{$project->id}",
        ])
        ->assertOk()
        ->assertJsonStructure(['auth', 'channel_data']);

    // Soft-deleted project denies everyone including Admin/Superadmin
    $project->delete();
    $this->actingAs($superadmin)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-project-board.{$project->id}",
        ])
        ->assertForbidden();
});
