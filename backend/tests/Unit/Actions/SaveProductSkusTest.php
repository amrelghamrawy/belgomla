<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveProductSkus;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\ProductOptionData;
use App\Modules\Catalog\Data\ProductOptionValueData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Enums\SkuStatus;
use App\Modules\Catalog\Exceptions\BaseUnitLocked;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\ProductReferences;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Models\Order;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->pen = $this->variantProduct(['أزرق', 'أسود', 'أحمر']);
    $this->piece = Unit::query()->where('name', 'قطعة')->sole();
    $this->sku = fn (string $code, array $values, array $extra = []): SkuData => new SkuData(...[
        'code' => $code, 'baseUnitId' => $this->piece->id, 'units' => [new SkuUnitData(null, $this->piece->id, 1, true, new SkuPricingData('10.00'))],
        'optionValues' => $values, ...$extra,
    ]);
    $this->colours = fn (string ...$values): ProductOptionData => new ProductOptionData(null, 'اللون', array_map(fn (string $value): ProductOptionValueData => new ProductOptionValueData(null, $value), array_values($values)));
    $this->save = function (array $skus, array $options): Product {
        $data = new ProductData(name: 'قلم جاف', categoryId: $this->pen->category_id, skus: array_values(array_map(fn (SkuData $sku, int $index): SkuData => new SkuData(...[...get_object_vars($sku), 'path' => "skus.{$index}"]), $skus, array_keys($skus))), options: $options);
        app(SaveProductSkus::class)->handle($this->pen, $data, app(ProductReferences::class)->matches($data, $this->pen));

        return $this->pen->refresh()->load('skus.optionValues', 'options.values');
    };
});

test('BR-P14 a variant left out with no history is deleted with its stock row', function (): void {
    $red = Sku::query()->where('code', 'PEN-3')->sole();
    SkuInventory::query()->whereKey($red->id)->update(['on_hand' => 0]);
    App\Modules\Inventory\Models\InventoryMovement::query()->where('sku_id', $red->id)->delete();

    $product = ($this->save)([($this->sku)('PEN-1', ['أزرق']), ($this->sku)('PEN-2', ['أسود'])], [($this->colours)('أزرق', 'أسود')]);

    expect($product->skus->pluck('code')->all())->toBe(['PEN-1', 'PEN-2'])
        ->and(Sku::query()->whereKey($red->id)->exists())->toBeFalse()
        ->and(SkuInventory::query()->whereKey($red->id)->exists())->toBeFalse();
});

test('BR-P05 BR-P14 a variant left out that has history is set inactive and keeps its label', function (): void {
    $red = Sku::query()->where('code', 'PEN-3')->sole();
    Order::factory()->withItems([['sku_id' => $red->id, 'quantity' => 1]])->create();

    $product = ($this->save)([($this->sku)('PEN-1', ['أزرق']), ($this->sku)('PEN-2', ['أسود'])], [($this->colours)('أزرق', 'أسود')]);

    expect($product->skus->pluck('code')->all())->toBe(['PEN-1', 'PEN-2', 'PEN-3'])
        ->and($red->fresh()?->status)->toBe(SkuStatus::Inactive)
        ->and($red->fresh()?->name)->toBe('أحمر')
        ->and(ProductOptionValue::query()->where('value', 'أحمر')->exists())->toBeFalse();
});

test('D93 a variant left out that is a bundle component is set inactive, not deleted', function (): void {
    $red = Sku::query()->where('code', 'PEN-3')->sole();
    SkuInventory::query()->whereKey($red->id)->update(['on_hand' => 0]);
    App\Modules\Inventory\Models\InventoryMovement::query()->where('sku_id', $red->id)->delete();
    BundleItem::factory()->create(['component_sku_unit_id' => $this->defaultUnitId($red)]);

    $product = ($this->save)([($this->sku)('PEN-1', ['أزرق']), ($this->sku)('PEN-2', ['أسود'])], [($this->colours)('أزرق', 'أسود')]);

    expect($product->skus->pluck('code')->all())->toBe(['PEN-1', 'PEN-2', 'PEN-3'])
        ->and($red->fresh()?->status)->toBe(SkuStatus::Inactive);
});

test('BR-P14 a variant sent without its id takes the place of the one with the same code', function (): void {
    $blue = Sku::query()->where('code', 'PEN-1')->sole();

    ($this->save)([($this->sku)('PEN-1', ['كحلي']), ($this->sku)('PEN-2', ['أسود']), ($this->sku)('PEN-3', ['أحمر'])], [($this->colours)('كحلي', 'أسود', 'أحمر')]);

    expect(Sku::query()->where('code', 'PEN-1')->sole()->id)->toBe($blue->id)
        ->and($blue->fresh()?->name)->toBe('كحلي');
});

test('BR-P14 a variant sent without id or known code takes the place of the one with the same combination', function (): void {
    $black = Sku::query()->where('code', 'PEN-2')->sole();

    ($this->save)([($this->sku)('PEN-1', ['أزرق']), ($this->sku)('PEN-BLK', ['اسود']), ($this->sku)('PEN-3', ['أحمر'])], [($this->colours)('أزرق', 'أسود', 'أحمر')]);

    expect($black->fresh()?->code)->toBe('PEN-BLK');
});

test('BR-P14 a new variant is created with its opening stock, the others keep theirs', function (): void {
    ($this->save)([
        ($this->sku)('PEN-1', ['أزرق']), ($this->sku)('PEN-2', ['أسود']), ($this->sku)('PEN-3', ['أحمر']), ($this->sku)('PEN-4', ['أخضر'], ['openingStock' => 7]),
    ], [($this->colours)('أزرق', 'أسود', 'أحمر', 'أخضر')]);

    $green = Sku::query()->with('inventory')->where('code', 'PEN-4')->sole();

    expect($green->inventory?->on_hand)->toBe(7)
        ->and($green->name)->toBe('أخضر')
        ->and($green->sort_order)->toBe(3)
        ->and(Sku::query()->with('inventory')->where('code', 'PEN-1')->sole()->inventory?->on_hand)->toBe(50);
});

test('BR-P14 an option and its values are matched by name and value, keeping their ids, in the order sent', function (): void {
    $option = ProductOption::query()->sole();
    $black = ProductOptionValue::query()->where('value', 'أسود')->sole();

    $product = ($this->save)([($this->sku)('PEN-2', ['أسود']), ($this->sku)('PEN-1', ['أزرق']), ($this->sku)('PEN-3', ['أحمر'])], [($this->colours)('أسود', 'أزرق', 'أحمر')]);

    expect($product->options->sole()->id)->toBe($option->id)
        ->and($product->options->sole()->values->pluck('value')->all())->toBe(['أسود', 'أزرق', 'أحمر'])
        ->and($product->options->sole()->values->first()?->id)->toBe($black->id)
        ->and($product->skus->pluck('code')->all())->toBe(['PEN-2', 'PEN-1', 'PEN-3']);
});

test('BR-P14 a second option gives every variant a value of each, the label naming both', function (): void {
    $sizes = new ProductOptionData(null, 'المقاس', [new ProductOptionValueData(null, 'S'), new ProductOptionValueData(null, 'M')]);

    $product = ($this->save)([($this->sku)('PEN-1', ['أزرق', 'S']), ($this->sku)('PEN-5', ['أزرق', 'M'])], [($this->colours)('أزرق'), $sizes]);

    expect($product->skus->where('status', SkuStatus::Active)->pluck('name')->all())->toBe(['أزرق / S', 'أزرق / M'])
        ->and($product->skus->firstWhere('code', 'PEN-5')?->optionValues)->toHaveCount(2)
        ->and($product->options->pluck('name')->all())->toBe(['اللون', 'المقاس']);
});

test('BR-P14 removing every option leaves one plain sku without a label', function (): void {
    $blue = Sku::query()->where('code', 'PEN-1')->sole();

    $product = ($this->save)([($this->sku)('PEN-1', [], ['id' => $blue->id])], []);

    expect($product->options)->toBeEmpty()
        ->and($product->skus->where('status', SkuStatus::Active)->pluck('code')->all())->toBe(['PEN-1'])
        ->and($blue->fresh()?->name)->toBeNull()
        ->and($product->skus->firstWhere('code', 'PEN-1')?->optionValues)->toBeEmpty();
});

test('BR-P14 a variant sent inactive stays in the form and is hidden from the store', function (): void {
    ($this->save)([($this->sku)('PEN-1', ['أزرق']), ($this->sku)('PEN-2', ['أسود'], ['status' => SkuStatus::Inactive]), ($this->sku)('PEN-3', ['أحمر'])], [($this->colours)('أزرق', 'أسود', 'أحمر')]);

    expect(Sku::query()->where('code', 'PEN-2')->sole()->status)->toBe(SkuStatus::Inactive)
        ->and($this->pen->activeSkus()->pluck('code')->all())->toBe(['PEN-1', 'PEN-3']);
});

test('D99 c the base unit of a sku holding stock cannot change, pointing at the field', function (): void {
    $dozen = Unit::factory()->create(['name' => 'دستة']);
    $sku = fn (string $code, string $colour): SkuData => ($this->sku)($code, [$colour], ['baseUnitId' => $dozen->id]);

    expect(fn () => ($this->save)([$sku('PEN-1', 'أزرق'), ($this->sku)('PEN-2', ['أسود']), ($this->sku)('PEN-3', ['أحمر'])], [($this->colours)('أزرق', 'أسود', 'أحمر')]))
        ->toThrow(function (BaseUnitLocked $e): void {
            expect([$e->status, $e->errorCode, $e->details])->toBe([422, 'validation_failed', ['skus.0.base_unit_id' => ['لا يمكن تغيير الوحدة الأساسية لصنف عليه رصيد أو حجز: صفّر رصيده أولًا']]]);
        });

    expect(Sku::query()->where('code', 'PEN-1')->sole()->base_unit_id)->toBe($this->piece->id);
});

test('D99 c the base unit of a sku with nothing on hand or reserved can change', function (): void {
    $dozen = Unit::factory()->create(['name' => 'دستة']);
    $blue = Sku::query()->where('code', 'PEN-1')->sole();
    SkuInventory::query()->whereKey($blue->id)->update(['on_hand' => 0, 'reserved' => 0]);

    ($this->save)([($this->sku)('PEN-1', ['أزرق'], ['baseUnitId' => $dozen->id]), ($this->sku)('PEN-2', ['أسود']), ($this->sku)('PEN-3', ['أحمر'])], [($this->colours)('أزرق', 'أسود', 'أحمر')]);

    expect($blue->fresh()?->base_unit_id)->toBe($dozen->id);
});
