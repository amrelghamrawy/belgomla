<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\SkuHistoryQuery;
use App\Modules\Customers\Models\CustomerSkuPrice;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->fresh = fn (): Sku => Product::factory()->withSku()->create()->skus()->sole();
});

test('BR-P05 a sku with an order line has history', function (): void {
    $sku = ($this->fresh)();
    Order::factory()->withItems([['sku_id' => $sku->id, 'quantity' => 1]])->create();

    expect(app(SkuHistoryQuery::class)->withHistory([$sku->id]))->toBe([$sku->id]);
});

test('BR-P05 a sku with a stock movement has history', function (): void {
    $sku = ($this->fresh)();
    InventoryMovement::factory()->create(['sku_id' => $sku->id]);

    expect(app(SkuHistoryQuery::class)->withHistory([$sku->id]))->toBe([$sku->id]);
});

test('BR-P05 a sku with a negotiated customer price has history', function (): void {
    $sku = ($this->fresh)();
    CustomerSkuPrice::factory()->create(['sku_id' => $sku->id, 'sku_unit_id' => $this->defaultUnitId($sku)]);

    expect(app(SkuHistoryQuery::class)->withHistory([$sku->id]))->toBe([$sku->id]);
});

test('BR-P05 a sku with only its stock row and cart lines has no history', function (): void {
    $sku = ($this->fresh)();

    expect(app(SkuHistoryQuery::class)->withHistory([$sku->id]))->toBe([]);
});

test('no sku asked means no query and no history', function (): void {
    expect(app(SkuHistoryQuery::class)->withHistory([]))->toBe([]);
});
