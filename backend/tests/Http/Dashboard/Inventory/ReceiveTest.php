<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Models\SkuInventory;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
    $this->sku = $this->inTenant(fn () => Sku::factory()->for(Product::factory())->withStock(100, 30, avgCost: '5.0000')->create());
    $this->carton = $this->addUnit($this->sku, ['conversion_factor' => 50]);
    $this->receive = fn (array $body, ?Sku $sku = null, array $headers = []) => $this->tenantJson('POST', '/api/dashboard/inventory/'.($sku ?? $this->sku)->id.'/receive', $body, headers: $headers);
});

test('BR-INV09 receiving cartons adds their base units at the price per carton and returns the row with the new average', function (): void {
    ($this->receive)(['quantity' => 2, 'sku_unit_id' => $this->carton->id, 'unit_cost' => '300', 'note' => 'مكتبة النور'])
        ->assertOk()
        ->assertJsonPath('data.sku_id', $this->sku->id)
        ->assertJsonPath('data.on_hand', 200)
        ->assertJsonPath('data.reserved', 30)
        ->assertJsonPath('data.available', 170)
        ->assertJsonPath('data.avg_cost', '5.5000'); // 2 × 50 = 100 at 300 ÷ 50 = 6.0000: (100 × 5 + 100 × 6) ÷ 200

    $this->assertDatabaseHas('inventory_movements', [
        'sku_id' => $this->sku->id, 'type' => 'receive', 'quantity' => 100, 'on_hand_after' => 200,
        'unit_cost' => '6.0000', 'reason' => 'مكتبة النور', 'actor_id' => $this->me->id,
    ]);
});

test('BR-L the receipt is audited with on hand and the average before and after', function (): void {
    ($this->receive)(['quantity' => 2, 'sku_unit_id' => $this->carton->id, 'unit_cost' => '300.00'])->assertOk();

    $audit = $this->inTenant(fn () => AuditLog::query()->sole());

    expect($audit->only(['action', 'entity_id', 'actor_id', 'old_values']))->toBe(['action' => 'stock.received', 'entity_id' => $this->sku->id, 'actor_id' => $this->me->id, 'old_values' => ['on_hand' => 100, 'avg_cost' => '5.0000']])
        ->and($audit->new_values)->toEqual([
            'on_hand' => 200, 'avg_cost' => '5.5000', 'quantity' => 2, 'sku_unit_id' => $this->carton->id,
            'quantity_base' => 100, 'unit_cost' => '300.00', 'note' => null,
        ]);
});

test('D96 a without a unit the quantity and the price are per base unit, and the first cost becomes the average', function (): void {
    $sku = $this->inTenant(fn () => Sku::factory()->for(Product::factory())->create());

    ($this->receive)(['quantity' => 12, 'unit_cost' => '7.25'], $sku)
        ->assertOk()
        ->assertJsonPath('data.on_hand', 12)
        ->assertJsonPath('data.avg_cost', '7.2500');
});

test('D96 b free goods come in at a zero cost and pull the average down', function (): void {
    ($this->receive)(['quantity' => 100, 'unit_cost' => '0'])
        ->assertOk()
        ->assertJsonPath('data.avg_cost', '2.5000'); // (100 × 5 + 100 × 0) ÷ 200
});

test('D96 a a unit of another sku is refused on sku_unit_id and nothing is written', function (): void {
    $other = $this->inTenant(fn () => Sku::factory()->for(Product::factory())->withStock(5)->create());
    $foreignUnit = $this->addUnit($other, ['conversion_factor' => 24]);

    ($this->receive)(['quantity' => 1, 'sku_unit_id' => $foreignUnit->id, 'unit_cost' => '10.00'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.sku_unit_id.0', 'الوحدة ليست من وحدات هذا الصنف');

    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('BR-INV09 a receipt above the stock ceiling in base units is refused on quantity', function (): void {
    $pallet = $this->addUnit($this->sku, ['conversion_factor' => 1000]);

    // 10,001 × 1,000 = 10,001,000 base units > 10,000,000
    ($this->receive)(['quantity' => 10_001, 'sku_unit_id' => $pallet->id, 'unit_cost' => '1.00'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['quantity']]]);

    $this->assertDatabaseCount('inventory_movements', 0);
});

test('D93 b a bundle has no stock to receive', function (): void {
    $component = $this->inTenant(fn () => Sku::factory()->for(Product::factory())->withStock(10)->create());
    $bundle = $this->bundleSku($this->gradeSet([$component->id => 2]));

    ($this->receive)(['quantity' => 1, 'unit_cost' => '10.00'], $bundle)
        ->assertConflict()
        ->assertJsonPath('error.code', 'is_bundle');

    $this->assertDatabaseCount('inventory_movements', 0);
});

test('receive validation', function (array $body, string $field): void {
    ($this->receive)($body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing quantity' => [['unit_cost' => '1.00'], 'quantity'],
    'zero quantity' => [['quantity' => 0, 'unit_cost' => '1.00'], 'quantity'],
    'BR-U05 fractional quantity' => [['quantity' => 2.5, 'unit_cost' => '1.00'], 'quantity'],
    'quantity too large' => [['quantity' => 10_000_001, 'unit_cost' => '1.00'], 'quantity'],
    'missing cost' => [['quantity' => 1], 'unit_cost'],
    'negative cost' => [['quantity' => 1, 'unit_cost' => '-1'], 'unit_cost'],
    'cost with three decimals' => [['quantity' => 1, 'unit_cost' => '1.005'], 'unit_cost'],
    'cost not a number' => [['quantity' => 1, 'unit_cost' => 'abc'], 'unit_cost'],
    'unit not an id' => [['quantity' => 1, 'unit_cost' => '1.00', 'sku_unit_id' => 'x'], 'sku_unit_id'],
    'note too long' => [['quantity' => 1, 'unit_cost' => '1.00', 'note' => str_repeat('س', 201)], 'note'],
]);

test('D55 the same Idempotency-Key twice receives the goods once', function (): void {
    ($this->receive)(['quantity' => 10, 'unit_cost' => '6.00'], headers: ['Idempotency-Key' => 'receipt-1'])->assertOk();
    ($this->receive)(['quantity' => 10, 'unit_cost' => '6.00'], headers: ['Idempotency-Key' => 'receipt-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->sku->id, 'on_hand' => 110]);
});

test('BR-T04 receiving for a sku of another tenant is 404 and changes nothing there', function (): void {
    $other = $this->otherTenant();
    $foreign = $this->inTenant(fn () => Sku::factory()->for(Product::factory())->withStock(7)->create(), $other);

    ($this->receive)(['quantity' => 5, 'unit_cost' => '1.00'], $foreign)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    expect($this->inTenant(fn () => SkuInventory::query()->findOrFail($foreign->id)->on_hand, $other))->toBe(7);
    $this->assertDatabaseCount('inventory_movements', 0);
});
