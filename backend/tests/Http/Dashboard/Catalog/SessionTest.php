<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;

test('a dashboard session from one tenant host cannot read the catalog of another tenant host', function (): void {
    $other = $this->otherTenant();
    $this->inTenant(fn () => Category::factory()->create(), $other);
    $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'secret123']);

    $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'secret123'])->assertOk();

    $this->tenantJson('GET', '/api/dashboard/categories', tenant: $other)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('a logged-in owner reads the catalog of their own host', function (): void {
    $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'secret123']);

    $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'secret123'])->assertOk();

    $this->tenantJson('GET', '/api/dashboard/categories')->assertOk();
});
