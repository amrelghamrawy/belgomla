<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\UpdateProduct;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
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
            'conversionFactor' => 1, 'basePrice' => '30.00', 'moq' => 2, ...$sku,
        ]),
        customFields: $customFields,
    );
});

test('BR-L a price and moq change is audited as exactly those fields', function (): void {
    app(UpdateProduct::class)->handle($this->product, ($this->data)(['basePrice' => '28.50', 'moq' => 5]));

    $log = AuditLog::query()->sole();

    expect($this->product->sku->base_price)->toBe('28.50')
        ->and($log->old_values)->toEqual(['sku.base_price' => '30.00', 'sku.moq' => 2])
        ->and($log->new_values)->toEqual(['sku.base_price' => '28.50', 'sku.moq' => 5]);
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
