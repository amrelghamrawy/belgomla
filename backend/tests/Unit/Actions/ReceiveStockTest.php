<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Data\StockReceiptData;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Exceptions\CostingRefused;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->sku = Sku::factory()->for(Product::factory())->withStock(120, avgCost: '5.0000')->create();
    $this->dozen = $this->addUnit($this->sku, ['conversion_factor' => 12]);
});

test('BR-INV09 a receipt in dozens becomes base units at the price per dozen and moves the average', function (): void {
    $user = $this->makeOwner();

    app(ReceiveStock::class)->handle($this->sku, new StockReceiptData(4, '75.00', $this->dozen->id, 'مورد الأقلام'), $user->id);

    // 4 × 12 = 48 at 75 ÷ 12 = 6.2500: (120 × 5 + 48 × 6.25) ÷ 168 = 900 ÷ 168 = 5.357142… → 5.3571
    expect(SkuInventory::query()->findOrFail($this->sku->id)->only(['on_hand', 'avg_cost']))->toBe(['on_hand' => 168, 'avg_cost' => '5.3571'])
        ->and(InventoryMovement::query()->sole()->only(['type', 'quantity', 'unit_cost', 'reason', 'actor_id']))->toBe([
            'type' => MovementType::Receive, 'quantity' => 48, 'unit_cost' => '6.2500', 'reason' => 'مورد الأقلام', 'actor_id' => $user->id,
        ])
        ->and(AuditLog::query()->sole()->only(['action', 'entity_type', 'entity_id', 'old_values', 'new_values']))->toEqual([
            'action' => 'stock.received', 'entity_type' => 'skus', 'entity_id' => $this->sku->id,
            'old_values' => ['on_hand' => 120, 'avg_cost' => '5.0000'],
            'new_values' => ['on_hand' => 168, 'avg_cost' => '5.3571', 'quantity' => 4, 'sku_unit_id' => $this->dozen->id, 'quantity_base' => 48, 'unit_cost' => '75.00', 'note' => 'مورد الأقلام'],
        ]);
});

test('D96 a a unit that is not one of the sku\'s is refused before anything is written', function (): void {
    $other = Sku::factory()->for(Product::factory())->create();

    expect(fn () => app(ReceiveStock::class)->handle($this->sku, new StockReceiptData(1, '10.00', $this->defaultUnitId($other))))
        ->toThrow(CostingRefused::class, 'البيانات غير صحيحة');

    expect(InventoryMovement::query()->count())->toBe(0);
});

test('BR-INV09 a receipt above the ceiling is refused with the ceiling in the message', function (): void {
    $pallet = $this->addUnit($this->sku, ['conversion_factor' => 1000]);

    expect(fn () => app(ReceiveStock::class)->handle($this->sku, new StockReceiptData(10_001, '1.00', $pallet->id)))
        ->toThrow(function (CostingRefused $e): void {
            expect($e->details)->toBe(['quantity' => ['الكمية بالوحدة الأساسية أكبر من الحد المسموح (10000000)']]);
        });
});

test('a failing write rolls the receipt back: on hand and the average as they were, no movement, no audit', function (): void {
    // the note is longer than its column, so the movement insert fails after the stock row was already changed
    expect(fn () => app(ReceiveStock::class)->handle($this->sku, new StockReceiptData(10, '9.00', note: str_repeat('س', 201))))->toThrow(QueryException::class);

    expect(SkuInventory::query()->findOrFail($this->sku->id)->only(['on_hand', 'avg_cost']))->toBe(['on_hand' => 120, 'avg_cost' => '5.0000'])
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});
