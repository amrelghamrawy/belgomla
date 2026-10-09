<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\CreateProduct;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\InvalidCustomFields;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $basics = $this->catalogBasics();
    $this->data = fn (array $sku = [], array $customFields = []): ProductData => new ProductData(
        name: 'قلم جاف',
        categoryId: $basics['category']->id,
        sku: new SkuData(...[
            'code' => 'PEN-1', 'sellingUnitId' => $basics['carton']->id, 'baseUnitId' => $basics['piece']->id,
            'conversionFactor' => 12, 'basePrice' => '30.00', ...$sku,
        ]),
        customFields: $customFields,
    );
});

test('BR-P02 creates an active product with exactly one sku and a system audit record', function (): void {
    $product = app(CreateProduct::class)->handle(($this->data)());

    expect($product->status)->toBe(ProductStatus::Active)
        ->and($product->skus()->count())->toBe(1)
        ->and($product->sku->conversion_factor)->toBe(12)
        ->and($product->sku->moq)->toBe(1)
        ->and($product->created_by)->toBeNull()
        ->and(AuditLog::query()->sole()->new_values)->toMatchArray(['name' => 'قلم جاف', 'sku.code' => 'PEN-1', 'sku.base_price' => '30.00']);
});

test('stores the creating user', function (): void {
    $owner = $this->makeOwner();

    expect(app(CreateProduct::class)->handle(($this->data)(), $owner->id)->created_by)->toBe($owner->id);
});

test('BR-P08 stores the normalised custom fields', function (): void {
    ProductFieldDefinition::factory()->number()->create(['key' => 'pages']);

    expect(app(CreateProduct::class)->handle(($this->data)(customFields: ['pages' => '96']))->custom_fields)->toBe(['pages' => 96]);
});

test('invalid custom fields stop the creation before anything is written', function (): void {
    expect(fn () => app(CreateProduct::class)->handle(($this->data)(customFields: ['nope' => 1])))->toThrow(InvalidCustomFields::class);

    expect(Product::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('a failing sku insert rolls back the product and the audit record', function (): void {
    expect(fn () => app(CreateProduct::class)->handle(($this->data)(['conversionFactor' => 0])))->toThrow(QueryException::class);

    expect(Product::query()->count())->toBe(0)
        ->and(Sku::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});
