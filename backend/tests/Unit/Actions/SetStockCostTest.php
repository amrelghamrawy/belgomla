<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Actions\SetStockCost;
use App\Modules\Inventory\Exceptions\CostingRefused;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->sku = Sku::factory()->for(Product::factory())->withStock(30, avgCost: '9.0000')->create();
});

test('D96 d the cost per carton is stored per base unit and audited with the average it replaced', function (): void {
    $carton = $this->addUnit($this->sku, ['conversion_factor' => 24]);

    app(SetStockCost::class)->handle($this->sku, '100.00', $carton->id);

    // 100 ÷ 24 = 4.16666… → 4.1667
    expect(SkuInventory::query()->findOrFail($this->sku->id)->avg_cost)->toBe('4.1667')
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(AuditLog::query()->sole()->only(['action', 'old_values', 'new_values']))->toEqual([
            'action' => 'stock.cost_set',
            'old_values' => ['avg_cost' => '9.0000'],
            'new_values' => ['avg_cost' => '4.1667', 'unit_cost' => '100.00', 'sku_unit_id' => $carton->id],
        ]);
});

test('D96 d the same cost again writes no audit', function (): void {
    app(SetStockCost::class)->handle($this->sku, '9.00');

    expect(AuditLog::query()->count())->toBe(0);
});

test('D93 b a bundle is refused with its own message', function (): void {
    $bundle = $this->bundleSku($this->gradeSet([$this->sku->id => 1]));

    expect(fn () => app(SetStockCost::class)->handle($bundle, '1.00'))->toThrow(CostingRefused::class, 'هذا المنتج باقة وليس له مخزون: استلم مكوناته');
});
