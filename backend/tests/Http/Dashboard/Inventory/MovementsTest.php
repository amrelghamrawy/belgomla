<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Models\InventoryMovement;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

test('BR-INV06 the movements of a sku, newest first, paginated with meta', function (): void {
    [$sku, $opening, $adjustment] = $this->inTenant(function (): array {
        $sku = Sku::factory()->create();

        return [
            $sku,
            InventoryMovement::factory()->for($sku)->create(),
            InventoryMovement::factory()->for($sku)->adjustment(-10, 90, 'تالف')->create(['actor_type' => 'tenant_user', 'actor_id' => $this->me->id]),
        ];
    });

    $this->tenantJson('GET', "/api/dashboard/inventory/{$sku->id}/movements?per_page=1")
        ->assertOk()
        ->assertExactJsonStructure([
            'data' => ['*' => ['id', 'type', 'type_label', 'quantity', 'on_hand_after', 'reason', 'order_id', 'actor' => ['type', 'id'], 'created_at']],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'],
        ])
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.id', $adjustment->id)
        ->assertJsonPath('data.0.type', 'adjustment')
        ->assertJsonPath('data.0.quantity', -10)
        ->assertJsonPath('data.0.on_hand_after', 90)
        ->assertJsonPath('data.0.reason', 'تالف')
        ->assertJsonPath('data.0.actor', ['type' => 'tenant_user', 'id' => $this->me->id]);

    expect($opening->id)->toBeLessThan($adjustment->id);
});

test('movements list validation', function (string $query, string $field): void {
    $sku = $this->inTenant(fn () => Sku::factory()->create());

    $this->tenantJson('GET', "/api/dashboard/inventory/{$sku->id}/movements?{$query}")
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'per page over 100' => ['per_page=101', 'per_page'],
    'page below 1' => ['page=0', 'page'],
]);

test('BR-T04 the movements of another tenant\'s sku are 404', function (): void {
    $foreign = $this->inTenant(fn () => InventoryMovement::factory()->create(), $this->otherTenant());

    $this->tenantJson('GET', "/api/dashboard/inventory/{$foreign->sku_id}/movements")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});
