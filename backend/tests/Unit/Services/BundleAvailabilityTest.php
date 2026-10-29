<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\SkuStatus;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Services\BundleAvailability;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->arabic = $this->orderableSku(['code' => 'AR-1'], onHand: 100, name: 'عربي'); // cartons of 20
    $this->maths = $this->orderableSku(['code' => 'MA-1'], onHand: 400, name: 'رياضيات');
    app(TenantContext::class)->set($this->tenant);

    $this->availableOf = fn (Product $bundle): int => app(BundleAvailability::class)->available(Sku::query()->with(BundleAvailability::RELATIONS)->where('product_id', $bundle->id)->sole());
});

test('BR-INV02 D93 the bundles available are the minimum over the components of their stock over what one bundle takes', function (): void {
    $bundle = $this->gradeSet([$this->arabic->id => 1, $this->maths->id => 2]);

    expect(($this->availableOf)($bundle))->toBe(5); // عربي 100 / 20 = 5, رياضيات 400 / 40 = 10
});

test('D91 D93 a component sold in a further unit counts that unit\'s factor', function (): void {
    $piece = $this->addUnit($this->maths, ['conversion_factor' => 1, 'base_price' => '5.00']);
    $bundle = $this->inTenant(fn () => $this->gradeSet([$this->arabic->id => 1]));
    BundleItem::query()->create(['bundle_sku_id' => $this->bundleSku($bundle)->id, 'component_sku_unit_id' => $piece->id, 'quantity' => 150, 'sort_order' => 1]);

    expect(($this->availableOf)($bundle))->toBe(2); // 400 pieces / 150
});

test('D93 a component out of stock makes the bundle unavailable', function (): void {
    $bundle = $this->gradeSet([$this->arabic->id => 1, $this->maths->id => 1]);
    SkuInventory::query()->whereKey($this->arabic->id)->update(['reserved' => 90]); // 10 left, half a carton

    expect(($this->availableOf)($bundle))->toBe(0);
});

test('D93 a component without a stock row counts as none', function (): void {
    $bundle = $this->gradeSet([$this->arabic->id => 1]);
    SkuInventory::query()->whereKey($this->arabic->id)->delete();

    expect(($this->availableOf)($bundle))->toBe(0);
});

test('D93 a component that can no longer be ordered makes the bundle unavailable', function (Closure $withdraw): void {
    $bundle = $this->gradeSet([$this->arabic->id => 1, $this->maths->id => 1]);
    $withdraw($this->maths);

    expect(($this->availableOf)($bundle))->toBe(0);
})->with([
    'its product inactive' => [fn (Sku $sku) => Product::query()->whereKey($sku->product_id)->update(['status' => ProductStatus::Inactive])],
    'its variant inactive' => [fn (Sku $sku) => Sku::query()->whereKey($sku->id)->update(['status' => SkuStatus::Inactive])],
    'its unit archived' => [fn (Sku $sku) => SkuUnit::query()->where('sku_id', $sku->id)->update(['status' => ArchiveStatus::Archived, 'is_default' => false])],
]);

test('D93 one component says how many bundles it allows on its own', function (): void {
    $bundle = $this->gradeSet([$this->maths->id => 3]);
    $item = BundleItem::query()->with('componentUnit.sku.product', 'componentUnit.sku.inventory')->where('bundle_sku_id', $this->bundleSku($bundle)->id)->sole();

    expect(app(BundleAvailability::class)->allows($item))->toBe(6) // 400 / 60
        ->and(app(BundleAvailability::class)->orderable($item))->toBeTrue();
});

test('D93 a bundle without components is never available', function (): void {
    $bundle = $this->gradeSet([$this->arabic->id => 1]);
    BundleItem::query()->delete();

    expect(($this->availableOf)($bundle))->toBe(0);
});
