<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

test('every path and method in the spec is a registered route', function (): void {
    /** @var array<string, array<string, mixed>> $paths */
    $paths = openApiSpec()['paths'];

    $documented = collect($paths)
        ->flatMap(fn (array $operations, string $path): array => array_map(
            fn (string $method): string => mb_strtoupper($method).' '.mb_ltrim($path, '/'),
            array_intersect(array_keys($operations), ['get', 'post', 'put', 'patch', 'delete']),
        ))
        ->sort()
        ->values()
        ->all();

    $registered = collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn (RouteDefinition $route): array => array_map(fn (string $method): string => $method.' '.$route->uri(), $route->methods()))
        ->all();

    expect(array_values(array_diff($documented, $registered)))->toBe([]);
});

test('the spec lists exactly the error codes the backend throws', function (): void {
    $documented = openApiSchema('ErrorCode')['enum'];

    expect($documented)->toBe(emittedErrorCodes());
});

test('the store tenant response has exactly the documented keys', function (): void {
    $data = $this->tenantJson('GET', '/api/store/tenant')->assertOk()->json('data');

    expect(array_keys($data))->toBe(openApiSchema('TenantSettings')['required']);
});

test('the dashboard login response has exactly the documented keys', function (): void {
    $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'secret123']);

    $data = $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'secret123'])
        ->assertOk()
        ->json('data');

    expect(array_keys($data))->toBe(openApiSchema('TenantUser')['required']);
});
