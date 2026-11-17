<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Contracts\ReservationHolders;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
    $this->sku = $this->inTenant(fn () => Product::factory()->withSku(onHand: 100, reserved: 80)->create()->skus()->sole());
});

test('BR-INV06 an owner sets the counted on hand: the row is returned, the movement and the audit record written', function (): void {
    $this->tenantJson('POST', "/api/dashboard/inventory/{$this->sku->id}/adjust", ['new_on_hand' => 150, 'reason' => 'stocktake'])
        ->assertOk()
        ->assertJsonPath('data.sku_id', $this->sku->id)
        ->assertJsonPath('data.on_hand', 150)
        ->assertJsonPath('data.available', 70);

    $audit = $this->inTenant(fn () => AuditLog::query()->sole());

    $this->assertDatabaseHas('inventory_movements', ['sku_id' => $this->sku->id, 'type' => 'adjustment', 'quantity' => 50, 'on_hand_after' => 150, 'reason' => 'جرد', 'reason_code' => 'stocktake', 'actor_id' => $this->me->id]);
    expect($audit->only(['action', 'entity_id', 'actor_id', 'old_values']))->toBe(['action' => 'stock.adjusted', 'entity_id' => $this->sku->id, 'actor_id' => $this->me->id, 'old_values' => ['on_hand' => 100]])
        ->and($audit->new_values)->toEqual(['on_hand' => 150, 'reason' => 'stocktake', 'note' => null]);
});

test('D97 the reason and the note are written as text, with the reason code', function (): void {
    $this->tenantJson('POST', "/api/dashboard/inventory/{$this->sku->id}/adjust", ['new_on_hand' => 90, 'reason' => 'damaged', 'note' => '  وقع الكرتون  '])
        ->assertOk()
        ->assertJsonPath('data.on_hand', 90);

    $this->assertDatabaseHas('inventory_movements', ['sku_id' => $this->sku->id, 'quantity' => -10, 'reason' => 'كسر أو تلف: وقع الكرتون', 'reason_code' => 'damaged']);
});

test('BR-INV08 going below the reserved quantity is refused with the exact contract payload, without leaking details', function (): void {
    config(['app.debug' => false]);
    app()->instance(ReservationHolders::class, new readonly class implements ReservationHolders
    {
        public function ordersHolding(Sku $sku): array
        {
            return [['number' => 1024, 'status' => 'confirmed', 'quantity_base' => 60], ['number' => 1031, 'status' => 'pending', 'quantity_base' => 20]];
        }
    });

    $this->tenantJson('POST', "/api/dashboard/inventory/{$this->sku->id}/adjust", ['new_on_hand' => 50, 'reason' => 'stocktake'])
        ->assertUnprocessable()
        ->assertExactJson(['error' => [
            'code' => 'inventory_below_reserved',
            'message' => 'لا يمكن تقليل المخزون إلى 50 لأن 80 وحدة محجوزة للطلبات: #1024، #1031. ألغِ الطلبات أو سلّمها أولًا.',
            'details' => [
                'reserved' => 80,
                'min_allowed' => 80,
                'orders' => [
                    ['number' => 1024, 'status' => 'confirmed', 'quantity_base' => 60],
                    ['number' => 1031, 'status' => 'pending', 'quantity_base' => 20],
                ],
            ],
        ]]);

    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->sku->id, 'on_hand' => 100]);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('D35 setting the current on hand again changes nothing and is not audited', function (): void {
    $this->tenantJson('POST', "/api/dashboard/inventory/{$this->sku->id}/adjust", ['new_on_hand' => 100, 'reason' => 'stocktake'])
        ->assertOk()
        ->assertJsonPath('data.on_hand', 100);

    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('adjust validation', function (array $body, string $field): void {
    $this->tenantJson('POST', "/api/dashboard/inventory/{$this->sku->id}/adjust", $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing on hand' => [['reason' => 'stocktake'], 'new_on_hand'],
    'BR-INV02 negative on hand' => [['new_on_hand' => -1, 'reason' => 'stocktake'], 'new_on_hand'],
    'BR-U05 fractional on hand' => [['new_on_hand' => 2.5, 'reason' => 'stocktake'], 'new_on_hand'],
    'on hand too large' => [['new_on_hand' => 10_000_001, 'reason' => 'stocktake'], 'new_on_hand'],
    'BR-INV06 missing reason' => [['new_on_hand' => 90], 'reason'],
    'D97 a reason outside the list' => [['new_on_hand' => 90, 'reason' => 'جرد'], 'reason'],
    'D97 the import reason is not the merchant\'s' => [['new_on_hand' => 90, 'reason' => 'import'], 'reason'],
    'D97 other needs a note' => [['new_on_hand' => 90, 'reason' => 'other'], 'note'],
    'note too long' => [['new_on_hand' => 90, 'reason' => 'damaged', 'note' => str_repeat('س', 151)], 'note'],
]);

test('BR-T04 adjusting a sku of another tenant is 404 and changes nothing there', function (): void {
    $foreign = $this->inTenant(fn () => Product::factory()->withSku(onHand: 7)->create()->skus()->sole(), $this->otherTenant());

    $this->tenantJson('POST', "/api/dashboard/inventory/{$foreign->id}/adjust", ['new_on_hand' => 0, 'reason' => 'stocktake'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $foreign->id, 'on_hand' => 7]);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('BR-INV08 the refusal names the open orders holding the stock (Orders fills ReservationHolders)', function (): void {
    $sku = $this->orderableSku(onHand: 400);
    $order = $this->placeOrder($this->makeCustomer(), [$sku->id => 6]);

    $this->tenantJson('POST', "/api/dashboard/inventory/{$sku->id}/adjust", ['new_on_hand' => 100, 'reason' => 'stocktake'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'inventory_below_reserved')
        ->assertJsonPath('error.details.orders', [['number' => $order->number, 'status' => 'pending', 'quantity_base' => 120]]);
});
