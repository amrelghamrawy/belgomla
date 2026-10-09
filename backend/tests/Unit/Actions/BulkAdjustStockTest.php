<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Actions\BulkAdjustStock;
use App\Modules\Inventory\Data\BulkStockAdjustmentData;
use App\Modules\Inventory\Data\StockAdjustmentData;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->foreign = $this->inTenant(fn () => Sku::factory()->withStock(10)->create(), $this->otherTenant());
    app(TenantContext::class)->set($this->tenant);
    [$this->counted, $this->held, $this->same] = [
        Sku::factory()->withStock(10)->create(),
        Sku::factory()->withStock(30, 20)->create(),
        Sku::factory()->withStock(7)->create(),
    ];
});

test('BR-INV08 applies what it can and reports each skipped sku with its reason, in one audit batch', function (): void {
    $result = app(BulkAdjustStock::class)->handle(new BulkStockAdjustmentData([
        $this->counted->id => new StockAdjustmentData(15, 'جرد'),
        $this->held->id => new StockAdjustmentData(5, 'جرد'),
        $this->same->id => new StockAdjustmentData(7, 'جرد'),
        $this->foreign->id => new StockAdjustmentData(0, 'جرد'),
    ]));

    $logs = AuditLog::query()->orderBy('id')->get();

    expect($result['applied'])->toBe(1)
        ->and($result['skipped'])->toBe([
            ['sku_id' => $this->held->id, 'reason' => 'below_reserved', 'reserved' => 20, 'min_allowed' => 20],
            ['sku_id' => $this->same->id, 'reason' => 'unchanged'],
            ['sku_id' => $this->foreign->id, 'reason' => 'not_found'],
        ])
        ->and(SkuInventory::query()->findOrFail($this->counted->id)->on_hand)->toBe(15)
        ->and(SkuInventory::query()->findOrFail($this->held->id)->on_hand)->toBe(30)
        ->and(InventoryMovement::query()->count())->toBe(1)
        ->and($logs->pluck('batch_id')->unique()->all())->toBe([$result['batch_id']])
        ->and($logs->pluck('action')->all())->toBe(['stock.adjusted', 'inventory.bulk_adjusted'])
        ->and($logs->last()?->new_values)->toBe(['applied' => 1, 'skipped' => 3]);
    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $this->foreign->id, 'on_hand' => 10]);
});

test('a failure part way rolls back every adjustment and audit record of the batch', function (): void {
    $data = new BulkStockAdjustmentData([
        $this->counted->id => new StockAdjustmentData(15, 'جرد'),
        $this->same->id => new StockAdjustmentData(8, str_repeat('س', 201)),
    ]);

    expect(fn () => app(BulkAdjustStock::class)->handle($data))->toThrow(QueryException::class);

    expect(SkuInventory::query()->findOrFail($this->counted->id)->on_hand)->toBe(10)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});
