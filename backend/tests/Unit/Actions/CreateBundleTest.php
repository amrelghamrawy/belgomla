<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\CreateBundle;
use App\Modules\Catalog\Actions\UpdateBundle;
use App\Modules\Catalog\Data\BundleComponentData;
use App\Modules\Catalog\Data\BundleData;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Enums\SkuStatus;
use App\Modules\Catalog\Exceptions\BundleRefused;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Pricing\Data\BundlePricingData;
use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Modules\Pricing\Models\BundlePricing;
use App\Modules\Tenancy\TenantContext;
use App\Support\ApiException;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->arabic = $this->orderableSku(['code' => 'AR-1', 'base_price' => '92.00'], name: 'عربي');
    $this->maths = $this->orderableSku(['code' => 'MA-1', 'base_price' => '113.50'], name: 'رياضيات');
    app(TenantContext::class)->set($this->tenant);
    $this->piece = Unit::query()->firstOrCreate(['name' => 'قطعة']);
    $this->category = Category::factory()->create(['name' => 'أولى إعدادي']);

    $this->data = fn (array $components = [], array $overrides = []): BundleData => new BundleData(...[
        'name' => 'طقم أولى إعدادي',
        'categoryId' => $this->category->id,
        'code' => 'SET-1',
        'unitId' => $this->piece->id,
        'components' => $components !== [] ? $components : [new BundleComponentData($this->defaultUnitId($this->arabic), 1), new BundleComponentData($this->defaultUnitId($this->maths), 2)],
        'pricing' => new BundlePricingData(BundlePriceMode::PercentOffSum, '10.00'),
        'moq' => 2,
        ...$overrides,
    ]);
    $this->refusal = function (Closure $act, string $field, string $message): void {
        expect($act)->toThrow(function (ApiException $e) use ($field, $message): void {
            expect($e->errorCode)->toBe('validation_failed')->and($e->details)->toBe([$field => [$message]]);
        });
    };
});

test('BR-P15 it creates a bundle product with one sku sold in one unit at the priced sum', function (): void {
    $product = app(CreateBundle::class)->handle(($this->data)());

    $sku = Sku::query()->where('product_id', $product->id)->sole();
    $unit = SkuUnit::query()->with('tiers')->where('sku_id', $sku->id)->sole();

    expect($product->type)->toBe(ProductType::Bundle)
        ->and($product->status)->toBe(ProductStatus::Active)
        ->and($sku->only(['code', 'base_unit_id']))->toBe(['code' => 'SET-1', 'base_unit_id' => $this->piece->id])
        ->and($unit->only(['unit_id', 'conversion_factor', 'base_price', 'cover_price', 'moq', 'is_default']))->toBe(['unit_id' => $this->piece->id, 'conversion_factor' => 1, 'base_price' => '287.10', 'cover_price' => null, 'moq' => 2, 'is_default' => true])
        ->and($unit->tiers)->toHaveCount(0);
});

test('BR-P15 the components are stored in the order given with their quantities', function (): void {
    $product = app(CreateBundle::class)->handle(($this->data)());

    expect(BundleItem::query()->orderBy('sort_order')->get(['component_sku_unit_id', 'quantity', 'sort_order'])->toArray())->toBe([
        ['component_sku_unit_id' => $this->defaultUnitId($this->arabic), 'quantity' => 1, 'sort_order' => 0],
        ['component_sku_unit_id' => $this->defaultUnitId($this->maths), 'quantity' => 2, 'sort_order' => 1],
    ])->and(BundleItem::query()->value('bundle_sku_id'))->toBe($this->bundleSku($product)->id);
});

test('BR-P15 the pricing is stored with the bundle', function (): void {
    $product = app(CreateBundle::class)->handle(($this->data)());

    expect(BundlePricing::query()->sole()->only(['bundle_sku_id', 'mode', 'value']))->toBe(['bundle_sku_id' => $this->bundleSku($product)->id, 'mode' => BundlePriceMode::PercentOffSum, 'value' => '10.00']);
});

test('BR-P15 a fixed price is the bundle unit\'s base price', function (): void {
    $product = app(CreateBundle::class)->handle(($this->data)(overrides: ['pricing' => new BundlePricingData(BundlePriceMode::Fixed, '250.00')]));

    expect(SkuUnit::query()->where('sku_id', $this->bundleSku($product)->id)->value('base_price'))->toBe('250.00');
});

test('BR-INV02 D93 a bundle has no stock row of its own', function (): void {
    $product = app(CreateBundle::class)->handle(($this->data)());

    expect(SkuInventory::query()->whereKey($this->bundleSku($product)->id)->exists())->toBeFalse();
});

test('BR-L the creation is audited with the components and pricing', function (): void {
    app(CreateBundle::class)->handle(($this->data)());

    expect(AuditLog::query()->where('action', 'product.created')->sole()->new_values)->toMatchArray([
        'name' => 'طقم أولى إعدادي',
        'sku.code' => 'SET-1',
        'bundle' => [
            'components' => [['sku_unit_id' => $this->defaultUnitId($this->arabic), 'quantity' => 1], ['sku_unit_id' => $this->defaultUnitId($this->maths), 'quantity' => 2]],
            'pricing' => ['mode' => 'percent_off_sum', 'value' => '10.00'],
        ],
    ]);
});

test('D93 an inactive status is kept, a generated set starts as a draft', function (): void {
    $product = app(CreateBundle::class)->handle(($this->data)(overrides: ['status' => ProductStatus::Inactive]));

    expect($product->status)->toBe(ProductStatus::Inactive);
});

test('D93 a bundle cannot be a component of another bundle', function (): void {
    $inner = app(CreateBundle::class)->handle(($this->data)());

    ($this->refusal)(fn () => app(CreateBundle::class)->handle(($this->data)([new BundleComponentData($this->defaultUnitId($this->bundleSku($inner)), 1)], ['code' => 'SET-2'])), 'components.0.sku_unit_id', 'لا يمكن وضع باقة داخل باقة');
});

test('D93 only units of active skus of active products can be components', function (Closure $withdraw): void {
    $data = ($this->data)();
    $withdraw($this->maths);

    ($this->refusal)(fn () => app(CreateBundle::class)->handle($data), 'components.1.sku_unit_id', 'الصنف غير نشط');
})->with([
    'an inactive product' => [fn (Sku $sku) => Product::query()->whereKey($sku->product_id)->update(['status' => ProductStatus::Inactive])],
    'a coming soon product' => [fn (Sku $sku) => Product::query()->whereKey($sku->product_id)->update(['status' => ProductStatus::ComingSoon])],
    'an inactive variant' => [fn (Sku $sku) => Sku::query()->whereKey($sku->id)->update(['status' => SkuStatus::Inactive])],
    'an archived unit' => [fn (Sku $sku) => SkuUnit::query()->where('sku_id', $sku->id)->update(['status' => ArchiveStatus::Archived, 'is_default' => false])],
]);

test('BR-T04 a unit of another store is not a component', function (): void {
    $foreign = $this->orderableSku(['code' => 'FR-1'], tenant: $this->otherTenant());

    ($this->refusal)(fn () => app(CreateBundle::class)->handle(($this->data)([new BundleComponentData(SkuUnit::query()->withoutGlobalScopes()->where('sku_id', $foreign->id)->value('id'), 1)])), 'components.0.sku_unit_id', 'العنصر المختار غير موجود');
});

test('BR-P03 the code is unique in the store', function (): void {
    app(CreateBundle::class)->handle(($this->data)(overrides: ['code' => 'AR-1']));
})->throws(CatalogConflict::class);

test('D25 an unknown category or unit is refused', function (string $field, array $overrides): void {
    ($this->refusal)(fn () => app(CreateBundle::class)->handle(($this->data)(overrides: $overrides)), $field, 'العنصر المختار غير موجود');
})->with([
    'category' => ['category_id', ['categoryId' => 999999]],
    'unit' => ['unit_id', ['unitId' => 999999]],
]);

test('D93 required custom fields are not required of a bundle', function (): void {
    App\Modules\Catalog\Models\ProductFieldDefinition::factory()->create(['key' => 'grade', 'is_required' => true]);

    $product = app(CreateBundle::class)->handle(($this->data)());

    expect($product->custom_fields)->toBe([]);
});

test('a failing step writes nothing', function (): void {
    expect(fn () => app(CreateBundle::class)->handle(($this->data)(overrides: ['moq' => 0])))->toThrow(QueryException::class)
        ->and(Product::query()->where('type', 'bundle')->count())->toBe(0)
        ->and(BundleItem::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

describe('update', function (): void {
    beforeEach(function (): void {
        $this->bundle = app(CreateBundle::class)->handle(($this->data)());
    });

    test('BR-P15 it replaces the fields, components and pricing, repricing the unit', function (): void {
        app(UpdateBundle::class)->handle($this->bundle, ($this->data)([new BundleComponentData($this->defaultUnitId($this->maths), 3)], [
            'name' => 'طقم رياضيات', 'code' => 'SET-9', 'pricing' => new BundlePricingData(BundlePriceMode::Fixed, '300.00'), 'moq' => 1,
        ]));

        $sku = $this->bundleSku($this->bundle);

        expect($this->bundle->refresh()->name)->toBe('طقم رياضيات')
            ->and($sku->code)->toBe('SET-9')
            ->and(BundleItem::query()->get(['component_sku_unit_id', 'quantity'])->toArray())->toBe([['component_sku_unit_id' => $this->defaultUnitId($this->maths), 'quantity' => 3]])
            ->and(SkuUnit::query()->where('sku_id', $sku->id)->sole()->only(['base_price', 'moq']))->toBe(['base_price' => '300.00', 'moq' => 1])
            ->and(BundlePricing::query()->sole()->mode)->toBe(BundlePriceMode::Fixed);
    });

    test('BR-L the update is audited with what changed, the price change on the unit', function (): void {
        app(UpdateBundle::class)->handle($this->bundle, ($this->data)(overrides: ['pricing' => new BundlePricingData(BundlePriceMode::Fixed, '300.00')]));

        $updated = AuditLog::query()->where('action', 'product.updated')->sole();

        expect($updated->old_values['bundle']['pricing'] ?? null)->toBe(['mode' => 'percent_off_sum', 'value' => '10.00'])
            ->and($updated->new_values['bundle']['pricing'] ?? null)->toBe(['mode' => 'fixed', 'value' => '300.00'])
            ->and(AuditLog::query()->where('action', 'sku.price_changed')->count())->toBe(1);
    });

    test('D91 a new unit archives the old one', function (): void {
        $set = Unit::factory()->create(['name' => 'طقم']);

        app(UpdateBundle::class)->handle($this->bundle, ($this->data)(overrides: ['unitId' => $set->id]));

        expect(SkuUnit::query()->where('sku_id', $this->bundleSku($this->bundle)->id)->orderBy('id')->get(['unit_id', 'status', 'is_default'])->map->only(['unit_id', 'status', 'is_default'])->all())->toBe([
            ['unit_id' => $this->piece->id, 'status' => ArchiveStatus::Archived, 'is_default' => false],
            ['unit_id' => $set->id, 'status' => ArchiveStatus::Active, 'is_default' => true],
        ]);
    });

    test('D93 a bundle cannot contain itself', function (): void {
        ($this->refusal)(fn () => app(UpdateBundle::class)->handle($this->bundle, ($this->data)([new BundleComponentData($this->defaultUnitId($this->bundleSku($this->bundle)), 1)])), 'components.0.sku_unit_id', 'لا يمكن وضع باقة داخل باقة');
    });

    test('D93 keeping its own code is not a conflict', function (): void {
        app(UpdateBundle::class)->handle($this->bundle, ($this->data)());

        expect($this->bundleSku($this->bundle)->code)->toBe('SET-1');
    });

    test('D93 a simple product is not a bundle to update', function (): void {
        app(UpdateBundle::class)->handle(Product::query()->findOrFail($this->arabic->product_id), ($this->data)());
    })->throws(BundleRefused::class);
});
