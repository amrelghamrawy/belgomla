<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Contracts\ReservationHolders;
use App\Modules\Inventory\Data\StockAdjustmentData;
use App\Modules\Inventory\Exceptions\BelowReserved;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Str;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->sku = Sku::factory()->withStock(100, 80)->create();
});

test('BR-INV06 an adjustment writes the movement with the resulting on hand and a stock.adjusted audit', function (): void {
    $user = $this->makeOwner();

    $changed = app(AdjustStock::class)->handle($this->sku, new StockAdjustmentData(120, 'جرد'), $user->id);

    expect($changed)->toBeTrue()
        ->and(InventoryMovement::query()->sole()->only(['quantity', 'on_hand_after', 'reason', 'actor_id']))->toBe(['quantity' => 20, 'on_hand_after' => 120, 'reason' => 'جرد', 'actor_id' => $user->id])
        ->and(AuditLog::query()->sole()->only(['action', 'entity_type', 'entity_id', 'old_values', 'new_values', 'batch_id']))->toEqual([
            'action' => 'stock.adjusted', 'entity_type' => 'skus', 'entity_id' => $this->sku->id,
            'old_values' => ['on_hand' => 100], 'new_values' => ['on_hand' => 120, 'reason' => 'جرد'], 'batch_id' => null,
        ]);
});

test('BR-INV08 the refusal lists the open orders holding the reservation and nothing is written', function (): void {
    app()->instance(ReservationHolders::class, new readonly class implements ReservationHolders
    {
        public function ordersHolding(Sku $sku): array
        {
            return [['number' => 1024, 'status' => 'confirmed', 'quantity_base' => 60], ['number' => 1031, 'status' => 'pending', 'quantity_base' => 20]];
        }
    });

    expect(fn () => app(AdjustStock::class)->handle($this->sku, new StockAdjustmentData(50, 'جرد')))
        ->toThrow(BelowReserved::class, 'لا يمكن تقليل المخزون إلى 50 لأن 80 وحدة محجوزة للطلبات: #1024، #1031. ألغِ الطلبات أو سلّمها أولًا.');

    expect(SkuInventory::query()->findOrFail($this->sku->id)->on_hand)->toBe(100)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('D35 adjusting to the current value writes no movement and no audit', function (): void {
    expect(app(AdjustStock::class)->handle($this->sku, new StockAdjustmentData(100, 'جرد')))->toBeFalse()
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('a bulk batch id is carried into the audit record', function (): void {
    $batchId = (string) Str::uuid();

    app(AdjustStock::class)->handle($this->sku, new StockAdjustmentData(90, 'جرد'), batchId: $batchId);

    expect(AuditLog::query()->sole()->batch_id)->toBe($batchId);
});
