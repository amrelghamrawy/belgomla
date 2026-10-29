<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $item = BundleItem::factory()->create()->fresh();

    expect(array_keys($item->toArray()))->toBe(['id', 'tenant_id', 'bundle_sku_id', 'component_sku_unit_id', 'quantity', 'sort_order', 'created_at', 'updated_at']);
});

test('BR-P15 an item belongs to its bundle sku and names a component unit', function (): void {
    $item = BundleItem::factory()->create(['quantity' => 3]);

    expect($item->bundleSku)->toBeInstanceOf(Sku::class)
        ->and($item->bundleSku->product->isBundle())->toBeTrue()
        ->and($item->componentUnit)->toBeInstanceOf(SkuUnit::class)
        ->and($item->quantity)->toBe(3);
});

test('BR-U03 one bundle takes quantity times the component unit factor in base units', function (): void {
    $unit = SkuUnit::factory()->create(['conversion_factor' => 12]);

    expect(BundleItem::factory()->create(['component_sku_unit_id' => $unit->id, 'quantity' => 2])->baseQuantity())->toBe(24);
});

test('D93 a sku lists its bundle items in the merchant order', function (): void {
    $first = BundleItem::factory()->create(['sort_order' => 1]);
    BundleItem::factory()->create(['bundle_sku_id' => $first->bundle_sku_id, 'sort_order' => 0]);

    expect(Sku::query()->findOrFail($first->bundle_sku_id)->bundleItems->pluck('sort_order')->all())->toBe([0, 1]);
});

test('D93 a component is in a bundle once', function (): void {
    $item = BundleItem::factory()->create();

    BundleItem::factory()->create(['bundle_sku_id' => $item->bundle_sku_id, 'component_sku_unit_id' => $item->component_sku_unit_id]);
})->throws(UniqueConstraintViolationException::class);

test('D93 a component quantity is at least 1', function (): void {
    BundleItem::factory()->create(['quantity' => 0]);
})->throws(QueryException::class);

test('D93 a unit used in a bundle cannot be deleted', function (): void {
    $item = BundleItem::factory()->create();

    SkuUnit::query()->whereKey($item->component_sku_unit_id)->delete();
})->throws(QueryException::class);

test('BR-T03 an item created in a tenant belongs to it and is invisible elsewhere', function (): void {
    $item = BundleItem::factory()->create();

    expect($item->tenant_id)->toBe($this->tenant->id)
        ->and($this->inTenant(fn () => BundleItem::query()->count(), $this->otherTenant()))->toBe(0);
});
