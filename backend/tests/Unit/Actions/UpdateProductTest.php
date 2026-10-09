<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\UpdateProduct;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
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

    $this->data = fn (array $sku = [], array $customFields = [], string $name = 'قلم'): ProductData => new ProductData(
        name: $name,
        categoryId: $basics['category']->id,
        sku: new SkuData(...[
            'code' => 'PEN-1', 'sellingUnitId' => $basics['carton']->id, 'baseUnitId' => $basics['piece']->id,
            'conversionFactor' => 1, 'pricing' => new SkuPricingData(basePrice: '30.00', moq: 2), ...$sku,
        ]),
        customFields: $customFields,
    );
});

test('BR-L a price and moq change is audited by pricing as exactly those changes, not as a product update', function (): void {
    app(UpdateProduct::class)->handle($this->product, ($this->data)(['pricing' => new SkuPricingData('28.50', moq: 5)]));

    $logs = AuditLog::query()->get()->keyBy('action');

    expect($this->product->sku->base_price)->toBe('28.50')
        ->and($logs->keys()->sort()->values()->all())->toBe(['product.updated', 'sku.moq_changed', 'sku.price_changed'])
        ->and($logs['sku.price_changed']->old_values)->toEqual(['base_price' => '30.00'])
        ->and($logs['sku.price_changed']->new_values)->toEqual(['base_price' => '28.50'])
        ->and($logs['sku.moq_changed']->new_values)->toEqual(['moq' => 5])
        ->and($logs['product.updated']->new_values)->toBe([]);
});

test('BR-PR03 tiers not sent are kept, tiers sent replace the old ones', function (): void {
    SkuPriceTier::factory()->for($this->product->sku)->create(['min_quantity' => 10, 'unit_price' => '25.00']);

    app(UpdateProduct::class)->handle($this->product->refresh(), ($this->data)(name: 'قلم أزرق'));
    $kept = $this->product->sku->tiers->pluck('unit_price', 'min_quantity')->all();

    app(UpdateProduct::class)->handle($this->product->refresh(), ($this->data)(['pricing' => new SkuPricingData('30.00', moq: 2, tiers: [new TierData(20, '24.00')])]));

    expect($kept)->toBe([10 => '25.00'])
        ->and($this->product->sku->tiers->pluck('unit_price', 'min_quantity')->all())->toBe([20 => '24.00']);
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

test('a failing sku update rolls back the product change', function (): void {
    expect(fn () => app(UpdateProduct::class)->handle($this->product, ($this->data)(['conversionFactor' => 0], name: 'جديد')))
        ->toThrow(QueryException::class);

    expect($this->product->refresh()->name)->toBe('قلم')
        ->and(AuditLog::query()->count())->toBe(0);
});
