<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

it('enforces auth middleware on every /app/* and /api/internal/* route and supports route caching', function (): void {
    $protectedRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(function (RoutingRoute $route): bool {
            $uri = ltrim($route->uri(), '/');

            return str_starts_with($uri, 'app') || str_starts_with($uri, 'api/internal');
        });

    expect($protectedRoutes->isNotEmpty())->toBeTrue();

    foreach ($protectedRoutes as $route) {
        expect($route->gatherMiddleware())
            ->toContain('web')
            ->toContain('auth');
    }

    $this->artisan('route:cache')->assertExitCode(0);
    $this->artisan('route:clear')->assertExitCode(0);
});
