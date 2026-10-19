<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
| BR-T06, D82 (rules/02 §2): a dashboard route is authorised by a `permission:` middleware. Only signing in and out and
| `me` (which tells the frontend the permissions) are open to every signed-in user.
*/

test('D82 every dashboard route except auth and me requires a permission', function (): void {
    $open = ['api/dashboard/auth/login', 'api/dashboard/auth/logout', 'api/dashboard/me'];

    $missing = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route): bool => str_starts_with($route->uri(), 'api/dashboard/') && ! in_array($route->uri(), $open, true))
        ->reject(fn (RouteDefinition $route): bool => array_any($route->gatherMiddleware(), fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'permission:')))
        ->map(fn (RouteDefinition $route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($missing)->toBe([]);
});
