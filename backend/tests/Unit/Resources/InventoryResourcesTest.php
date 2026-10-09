<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Http\Resources\BulkAdjustResultResource;
use App\Modules\Inventory\Http\Resources\InventoryRowResource;
use App\Modules\Inventory\Http\Resources\MovementResource;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-INV02 the inventory row key set: stock in base units, min_allowed is reserved', function (): void {
    $product = Product::factory()->withSku(['code' => 'ADW-1', 'moq' => 5, 'conversion_factor' => 20], onHand: 140, reserved: 30)->create(['name' => 'علوم']);
    $sku = Sku::query()->with(['product', 'inventory'])->findOrFail($product->sku()->sole()->id);

    expect(rendered(new InventoryRowResource($sku), dashboardRequest()))->toBe([
        'sku_id' => $sku->id,
        'product_id' => $product->id,
        'code' => 'ADW-1',
        'name' => 'علوم',
        'status' => 'active',
        'on_hand' => 140,
        'reserved' => 30,
        'available' => 110,
        'min_allowed' => 30,
        'moq' => 5,
        'conversion_factor' => 20,
        'low_stock' => false,
    ]);
});

test('D36 a sku without a stock row shows zeros and is low on stock', function (): void {
    $sku = Sku::query()->with(['product', 'inventory'])->findOrFail(Sku::factory()->create()->id);

    $row = rendered(new InventoryRowResource($sku), dashboardRequest());

    expect([$row['on_hand'], $row['reserved'], $row['available'], $row['low_stock']])->toBe([0, 0, 0, true]);
});

test('BR-INV06 the movement key set', function (): void {
    $user = $this->makeOwner();
    $movement = InventoryMovement::factory()->adjustment(-10, 90, 'تالف')->create(['actor_type' => 'tenant_user', 'actor_id' => $user->id])->fresh();

    expect(rendered(new MovementResource($movement), dashboardRequest()))->toBe([
        'id' => $movement->id,
        'type' => 'adjustment',
        'type_label' => 'تعديل يدوي',
        'quantity' => -10,
        'on_hand_after' => 90,
        'reason' => 'تالف',
        'order_id' => null,
        'actor' => ['type' => 'tenant_user', 'id' => $user->id],
        'created_at' => $movement->created_at->toIso8601String(),
    ]);
});

test('the bulk adjust result key set', function (): void {
    $skipped = [['sku_id' => 3, 'reason' => 'unchanged']];

    expect(rendered(new BulkAdjustResultResource(['applied' => 2, 'skipped' => $skipped, 'batch_id' => 'b-1']), dashboardRequest()))
        ->toBe(['applied' => 2, 'skipped' => $skipped, 'batch_id' => 'b-1']);
});
