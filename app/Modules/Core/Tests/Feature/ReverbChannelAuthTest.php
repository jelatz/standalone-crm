<?php

use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;

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
