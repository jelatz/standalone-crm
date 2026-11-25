<?php

use App\Modules\Core\Attachments\Models\Attachment;
use App\Modules\Core\Rbac\Enums\RoleEnum;
use App\Modules\Core\Users\Enums\UserStatusEnum;
use App\Modules\Core\Users\Models\Department;
use App\Modules\Core\Users\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Controller as InertiaController;

it('enforces auth middleware and explicit authorization on every /app/* and /api/internal/* route and supports route caching', function (): void {
    $protectedRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(function (RoutingRoute $route): bool {
            $uri = ltrim($route->uri(), '/');

            return $uri === 'app'
                || str_starts_with($uri, 'app/')
                || $uri === 'api/internal'
                || str_starts_with($uri, 'api/internal/');
        });

    expect($protectedRoutes->isNotEmpty())->toBeTrue();

    foreach ($protectedRoutes as $route) {
        expect($route->gatherMiddleware())
            ->toContain('web')
            ->toContain('auth');

        $actionName = ltrim($route->getActionName(), '\\');
        $controllerClass = $route->getControllerClass();

        if (
            $actionName === InertiaController::class
            || ($controllerClass !== null && ltrim($controllerClass, '\\') === InertiaController::class)
        ) {
            continue;
        }

        expect($actionName)->not->toBe('Closure');

        $methodName = $route->getActionMethod();

        if ($methodName === $controllerClass) {
            $methodName = '__invoke';
        }

        expect($controllerClass)->not->toBeNull()
            ->and(method_exists($controllerClass, $methodName))->toBeTrue();

        $reflection = new ReflectionMethod($controllerClass, $methodName);
        $fileName = $reflection->getFileName();
        expect($fileName)->toBeString();

        $lines = file($fileName);
        expect($lines)->toBeArray();

        $methodSource = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        expect($methodSource)->toMatch(
            '/Gate::[^\n;]*\bauthorize\s*\(|\$this->authorize\s*\(|->authorizeForUser\s*\(/',
            sprintf('Route [%s] (%s::%s) must explicitly invoke authorization.', $route->uri(), $controllerClass, $methodName),
        );
    }

    $this->artisan('route:cache')->assertExitCode(0);
    $this->artisan('route:clear')->assertExitCode(0);
});

it('denies AttachmentController::presign() with 403 for an authenticated 0-role user', function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    Storage::fake('s3');

    $dept = Department::query()->create(['name' => 'Audit Dept', 'slug' => 'audit-dept']);

    $zeroRoleUser = User::query()->create([
        'employee_code' => 'EMP-AUDIT-0',
        'name' => 'Zero Role User',
        'email' => 'zero.role@acme.com',
        'department_id' => $dept->id,
        'status' => UserStatusEnum::Active,
    ]);

    $salesUser = User::query()->create([
        'employee_code' => 'EMP-AUDIT-1',
        'name' => 'Sales User',
        'email' => 'sales.audit@acme.com',
        'department_id' => $dept->id,
        'status' => UserStatusEnum::Active,
    ]);
    $salesUser->assignRole(RoleEnum::Sales->value);

    expect(Gate::forUser($zeroRoleUser)->allows('create', Attachment::class))->toBeFalse()
        ->and(Gate::forUser($salesUser)->allows('create', Attachment::class))->toBeTrue();

    $payload = [
        'filename' => 'quote.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => hash('sha256', 'audit-bytes'),
    ];

    $this->actingAs($zeroRoleUser)
        ->postJson('/api/internal/attachments/presign', $payload)
        ->assertForbidden();

    $this->actingAs($salesUser)
        ->postJson('/api/internal/attachments/presign', $payload)
        ->assertCreated();
});
