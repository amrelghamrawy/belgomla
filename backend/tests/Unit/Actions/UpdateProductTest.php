<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\UpdateProduct;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $basics = $this->catalogBasics();
    $this->product = Product::factory()->withSku([
        'code' => 'PEN-1', 'selling_unit_id' => $basics['carton']->id, 'base_unit_id' => $basics['piece']->id, 'base_price' => '30.00', 'moq' => 2,
    ])->create(['name' => 'قلم', 'category_id' => $basics['category']->id]);

    $this->unit = fn (array $unit = []): SkuUnitData => new SkuUnitData(...[
        'id' => null, 'unitId' => $basics['carton']->id, 'conversionFactor' => 1, 'isDefault' => true,
        'pricing' => new SkuPricingData(basePrice: '30.00', moq: 2), ...$unit,
    ]);
    $this->data = fn (array $sku = [], array $customFields = [], string $name = 'قلم', array $more = []): ProductData => new ProductData(
        name: $name,
        categoryId: $basics['category']->id,
        skus: [new SkuData('PEN-1', $basics['piece']->id, [($this->unit)($sku), ...$more])],
        customFields: $customFields,
    );
    $this->sold = fn (): array => SkuUnit::query()->where('sku_id', $this->product->skus()->sole()->id)->orderBy('id')->get()
        ->map->only(['unit_id', 'conversion_factor', 'base_price', 'is_default', 'status'])->map(fn (array $unit): array => [...$unit, 'status' => $unit['status']->value])->all();
});

test('BR-L a price and moq change is audited by pricing as exactly those changes, not as a product update', function (): void {
    app(UpdateProduct::class)->handle($this->product, ($this->data)(['pricing' => new SkuPricingData('28.50', moq: 5)]));

    $logs = AuditLog::query()->get()->keyBy('action');

    expect($this->product->skus->first()?->activeUnits->sole()->base_price)->toBe('28.50')
        ->and($logs->keys()->sort()->values()->all())->toBe(['product.updated', 'sku.moq_changed', 'sku.price_changed'])
        ->and($logs['sku.price_changed']->old_values)->toEqual(['base_price' => '30.00'])
        ->and($logs['sku.price_changed']->new_values)->toEqual(['base_price' => '28.50'])
        ->and($logs['sku.moq_changed']->new_values)->toEqual(['moq' => 5])
        ->and($logs->pluck('entity_type', 'action')->all())->toEqual(['product.updated' => 'products', 'sku.moq_changed' => 'sku_units', 'sku.price_changed' => 'sku_units'])
        ->and($logs['product.updated']->new_values)->toBe([]);
});

test('BR-PR03 tiers not sent are kept, tiers sent replace the old ones', function (): void {
    SkuPriceTier::factory()->create(['sku_id' => $this->product->skus()->sole()->id, 'min_quantity' => 10, 'unit_price' => '25.00']);

    app(UpdateProduct::class)->handle($this->product->refresh(), ($this->data)(name: 'قلم أزرق'));
    $kept = $this->product->skus->first()?->activeUnits->sole()->tiers->pluck('unit_price', 'min_quantity')->all();

    app(UpdateProduct::class)->handle($this->product->refresh(), ($this->data)(['pricing' => new SkuPricingData('30.00', moq: 2, tiers: [new TierData(20, '24.00')])]));

    expect($kept)->toBe([10 => '25.00'])
        ->and($this->product->skus->first()?->activeUnits->sole()->tiers->pluck('unit_price', 'min_quantity')->all())->toBe([20 => '24.00']);
});

test('BR-P08 archived field values survive an update that does not send them', function (): void {
    ProductFieldDefinition::factory()->archived()->create(['key' => 'old_code']);
    ProductFieldDefinition::factory()->create(['key' => 'isbn']);
    $this->product->update(['custom_fields' => ['old_code' => 'X-1', 'isbn' => '1']]);

    app(UpdateProduct::class)->handle($this->product, ($this->data)(customFields: ['isbn' => '2']));

    expect($this->product->refresh()->custom_fields)->toEqual(['isbn' => '2', 'old_code' => 'X-1']);
});

test('the status and the slug are never changed by an update', function (): void {
    $this->product->update(['status' => 'coming_soon']);
    $slug = $this->product->slug;

    app(UpdateProduct::class)->handle($this->product, ($this->data)(name: 'قلم رصاص'));

    expect($this->product->refresh()->status->value)->toBe('coming_soon')
        ->and($this->product->slug)->toBe($slug)
        ->and($this->product->name)->toBe('قلم رصاص');
});

test('a product that lost its sku gets one on update', function (): void {
    $this->product->skus()->delete();

    app(UpdateProduct::class)->handle($this->product->refresh(), ($this->data)());

    expect($this->product->skus()->count())->toBe(1);
});

test('D91 a unit is added next to the kept one and becomes the default when flagged, the product audit lists the units', function (): void {
    $basics = $this->catalogBasics();

    app(UpdateProduct::class)->handle($this->product, ($this->data)(['isDefault' => false], more: [($this->unit)(['unitId' => $basics['piece']->id, 'pricing' => new SkuPricingData('3.00')])]));

    expect(($this->sold)())->toBe([
        ['unit_id' => $basics['carton']->id, 'conversion_factor' => 1, 'base_price' => '30.00', 'is_default' => false, 'status' => 'active'],
        ['unit_id' => $basics['piece']->id, 'conversion_factor' => 1, 'base_price' => '3.00', 'is_default' => true, 'status' => 'active'],
    ])
        ->and(AuditLog::query()->where('action', 'product.updated')->sole()->new_values)->toEqual(['sku.units' => [
            ['unit_id' => $basics['piece']->id, 'conversion_factor' => 1, 'is_default' => true],
            ['unit_id' => $basics['carton']->id, 'conversion_factor' => 1, 'is_default' => false],
        ]]);
});

test('D91 a unit left out is archived, and sent again it comes back with its row', function (): void {
    $basics = $this->catalogBasics();
    $carton = $this->product->skus()->sole()->defaultUnit;

    app(UpdateProduct::class)->handle($this->product, ($this->data)(['unitId' => $basics['piece']->id, 'pricing' => new SkuPricingData('3.00')]));
    $archived = SkuUnit::query()->findOrFail($carton?->id)->status->value;
    app(UpdateProduct::class)->handle($this->product->refresh(), ($this->data)());

    expect($archived)->toBe('archived')
        ->and(SkuUnit::query()->findOrFail($carton?->id)->only(['is_default', 'base_price']))->toBe(['is_default' => true, 'base_price' => '30.00'])
        ->and(SkuUnit::query()->where('status', 'active')->pluck('id')->all())->toBe([$carton?->id]);
});

test('a failing sku update rolls back the product change', function (): void {
    expect(fn () => app(UpdateProduct::class)->handle($this->product, ($this->data)(['conversionFactor' => 0], name: 'جديد')))
        ->toThrow(QueryException::class);

    expect($this->product->refresh()->name)->toBe('قلم')
        ->and(AuditLog::query()->count())->toBe(0);
});

test('V2 Task 06 inside a batch every record carries it, and an update that changes nothing writes nothing', function (): void {
    $batch = '0d1c2b3a-4f5e-4d6c-8b7a-9f8e7d6c5b4a';

    app(UpdateProduct::class)->handle($this->product, ($this->data)(), $batch);
    $unchanged = AuditLog::query()->count();
    app(UpdateProduct::class)->handle($this->product->refresh(), ($this->data)(['pricing' => new SkuPricingData('28.50', moq: 2)], name: 'قلم أحمر'), $batch);

    expect($unchanged)->toBe(0)
        ->and(AuditLog::query()->pluck('batch_id', 'action')->all())->toEqual(['product.updated' => $batch, 'sku.price_changed' => $batch]);
});

test('without a batch an update that changes nothing is still recorded, as the dashboard save', function (): void {
    app(UpdateProduct::class)->handle($this->product, ($this->data)());

    expect(AuditLog::query()->pluck('action')->all())->toBe(['product.updated']);
});

test('D88 the pre-order settings change when sent and are kept when left out, audited as product fields', function (): void {
    $sent = new ProductData(name: 'قلم', categoryId: $this->product->category_id, skus: ($this->data)()->skus, preorder: new App\Modules\Catalog\Data\PreorderData(true, null, 40));

    app(UpdateProduct::class)->handle($this->product, $sent);
    app(UpdateProduct::class)->handle($this->product->refresh(), ($this->data)());

    expect($this->product->refresh()->only(['preorder_enabled', 'expected_at', 'preorder_limit']))->toBe(['preorder_enabled' => true, 'expected_at' => null, 'preorder_limit' => 40])
        ->and(AuditLog::query()->where('action', 'product.updated')->orderBy('id')->firstOrFail()->only(['old_values', 'new_values']))
        ->toEqual(['old_values' => ['preorder_enabled' => false, 'preorder_limit' => null], 'new_values' => ['preorder_enabled' => true, 'preorder_limit' => 40]]);
});
