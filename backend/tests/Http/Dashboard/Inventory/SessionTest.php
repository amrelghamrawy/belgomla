<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;

test('a dashboard session from one tenant host cannot adjust stock on another tenant host', function (): void {
    $other = $this->otherTenant();
    $foreign = $this->inTenant(fn () => Product::factory()->withSku(onHand: 7)->create()->sku()->sole(), $other);
    $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'secret123']);

    $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'secret123'])->assertOk();

    $this->tenantJson('POST', "/api/dashboard/inventory/{$foreign->id}/adjust", ['new_on_hand' => 0, 'reason' => 'جرد'], $other)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $foreign->id, 'on_hand' => 7]);
});
