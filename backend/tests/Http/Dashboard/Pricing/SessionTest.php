<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;

test('a dashboard session from one tenant host cannot reprice products on another tenant host', function (): void {
    $other = $this->otherTenant();
    $foreign = $this->inTenant(fn () => Product::factory()->withSku(['cover_price' => '120.00', 'base_price' => '100.00'])->create(['name' => 'علوم']), $other);
    $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'secret123']);

    $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'secret123'])->assertOk();

    $this->tenantJson('POST', '/api/dashboard/pricing/bulk', ['filter' => ['q' => 'علوم'], 'base_price_percent' => 50, 'tiers' => []], $other)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    $this->assertDatabaseHas('skus', ['product_id' => $foreign->id, 'base_price' => '100.00']);
});
