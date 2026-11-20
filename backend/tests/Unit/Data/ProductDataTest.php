<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\BulkStatusData;
use App\Modules\Catalog\Data\PreorderData;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Http\Requests\BulkProductStatusRequest;
use App\Modules\Catalog\Http\Requests\ListDashboardProductsRequest;
use App\Modules\Catalog\Http\Requests\StoreProductListRequest;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $payload
 */
function productRequest(string $class, array $payload): StoreProductRequest|UpdateProductRequest
{
    $request = $class::create('/', 'POST', $payload);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    return $request;
}

test('product data trims text, normalises money and keeps the raw custom fields', function (): void {
    $data = ProductData::fromRequest(productRequest(StoreProductRequest::class, $this->productPayload([
        'name' => '  قلم  ', 'description' => '  ', 'custom_fields' => ['grade' => '12'], 'status' => 'coming_soon',
        'sku' => ['code' => 'PEN-1', 'units' => [['base_price' => 30, 'cover_price' => '40.5', 'base_price_percent' => '25']]],
    ])));
    $unit = $data->skus[0]->units[0];

    expect($data->name)->toBe('قلم')
        ->and($data->description)->toBeNull()
        ->and($data->customFields)->toBe(['grade' => '12'])
        ->and($data->status)->toBe(ProductStatus::ComingSoon)
        ->and($data->skus[0]->code)->toBe('PEN-1')
        ->and($unit->pricing->basePrice)->toBeNull()
        ->and($unit->pricing->coverPrice)->toBe('40.50')
        ->and($unit->pricing->coverPriceGiven)->toBeTrue()
        ->and($unit->pricing->basePricePercent)->toBe('25.00')
        ->and($unit->conversionFactor)->toBe(20)
        ->and($unit->id)->toBeNull()
        ->and($unit->isDefault)->toBeTrue()
        ->and($unit->pricing->moq)->toBe(5)
        ->and($unit->pricing->tiers)->toBeNull();
});

test('D91 the units are read in order, each with its own prices, the one flagged the default', function (): void {
    $basics = $this->catalogBasics();
    $data = ProductData::fromRequest(productRequest(StoreProductRequest::class, $this->productPayload(['sku' => ['units' => [
        1 => ['id' => '7', 'unit_id' => (string) $basics['piece']->id, 'conversion_factor' => '1', 'base_price' => '5', 'moq' => '12', 'is_default' => true],
    ]]])));

    expect(array_map(fn (App\Modules\Catalog\Data\SkuUnitData $unit): array => [$unit->id, $unit->unitId, $unit->conversionFactor, $unit->isDefault, $unit->pricing->basePrice, $unit->pricing->moq], $data->skus[0]->units))->toBe([
        [null, $basics['carton']->id, 20, false, '90.00', 5],
        [7, $basics['piece']->id, 1, true, '5.00', 12],
    ])
        ->and($data->skus[0]->defaultUnit()->unitId)->toBe($basics['piece']->id);
});

test('D91 without a flag the first unit is the default', function (): void {
    $data = ProductData::fromRequest(productRequest(StoreProductRequest::class, $this->productPayload(['sku' => ['units' => [
        1 => ['unit_id' => $this->catalogBasics()['piece']->id, 'conversion_factor' => 1, 'base_price' => '5'],
    ]]])));

    expect(array_map(fn (App\Modules\Catalog\Data\SkuUnitData $unit): bool => $unit->isDefault, $data->skus[0]->units))->toBe([true, false])
        ->and($data->skus[0]->defaultUnit()->unitId)->toBe($this->catalogBasics()['carton']->id);
});

test('BR-PR10 sku tiers are read with the percent winning over a sent price', function (): void {
    $data = ProductData::fromRequest(productRequest(StoreProductRequest::class, $this->productPayload([
        'sku' => ['units' => [['tiers' => [['min_quantity' => '10', 'unit_price' => 85], ['min_quantity' => 50, 'unit_price' => '70', 'discount_percent' => 40]]]]],
    ])));

    expect($data->skus[0]->units[0]->pricing->tiers)->toEqual([
        new App\Modules\Pricing\Data\TierData(10, '85.00'),
        new App\Modules\Pricing\Data\TierData(50, null, '40.00'),
    ]);
});

test('sku data defaults the moq to 1 and leaves unsent prices null', function (): void {
    $payload = $this->productPayload(['sku' => ['units' => [['cover_price' => null]]]]);
    unset($payload['sku']['units'][0]['moq']);

    $data = ProductData::fromRequest(productRequest(StoreProductRequest::class, $payload));

    expect($data->skus[0]->units[0]->pricing->moq)->toBe(1)
        ->and($data->skus[0]->units[0]->pricing->coverPrice)->toBeNull()
        ->and($data->skus[0]->units[0]->pricing->basePrice)->toBe('90.00')
        ->and($data->skus[0]->units[0]->pricing->basePricePercent)->toBeNull()
        ->and($data->status)->toBeNull();
});

test('sku data maps to the skus identity columns, each unit to its sku_units columns; prices are left to pricing', function (): void {
    $data = ProductData::fromRequest(productRequest(UpdateProductRequest::class, $this->productPayload()));

    expect($data->skus[0]->attributes())->toBe([
        'code' => 'ADW-SCI-P1-T1',
        'base_unit_id' => $this->catalogBasics()['piece']->id,
        'status' => App\Modules\Catalog\Enums\SkuStatus::Active,
        'sort_order' => 0,
    ])
        ->and($data->skus[0]->units[0]->attributes(3))->toBe([
            'unit_id' => $this->catalogBasics()['carton']->id,
            'conversion_factor' => 20,
            'is_default' => true,
            'sort_order' => 3,
            'status' => 'active',
        ])
        ->and($data->status)->toBeNull();
});

test('the dashboard filter reads the category id, fields, search and status', function (): void {
    $request = ListDashboardProductsRequest::create('/', 'GET', ['category' => '7', 'f' => ['grade' => 12, 'colored' => true], 'q' => '  علوم ', 'status' => 'inactive']);

    $filter = ProductFilterData::fromRequest($request);

    expect($filter->categoryId)->toBe(7)
        ->and($filter->categorySlug)->toBeNull()
        ->and($filter->fields)->toBe(['grade' => '12', 'colored' => 'true'])
        ->and($filter->q)->toBe('علوم')
        ->and($filter->status)->toBe(ProductStatus::Inactive)
        ->and($filter->isEmpty())->toBeFalse();
});

test('the store filter reads the category slug', function (): void {
    $filter = ProductFilterData::fromRequest(StoreProductListRequest::create('/', 'GET', ['category' => 'إعدادي']));

    expect($filter->categorySlug)->toBe('إعدادي')
        ->and($filter->categoryId)->toBeNull();
});

test('a filter with nothing in it is empty, and without() drops one facet', function (): void {
    $empty = ProductFilterData::fromRequest(StoreProductListRequest::create('/', 'GET', ['q' => ' ']));
    $filter = new ProductFilterData(fields: ['grade' => '1', 'term' => '2']);

    expect($empty->isEmpty())->toBeTrue()
        ->and($filter->without('grade')->fields)->toBe(['term' => '2']);
});

test('bulk status data reads the nested filter and the target status', function (): void {
    $data = BulkStatusData::fromRequest(BulkProductStatusRequest::create('/', 'POST', ['filter' => ['f' => ['academic_year' => 3]], 'status' => 'inactive']));

    expect($data->status)->toBe(ProductStatus::Inactive)
        ->and($data->filter->fields)->toBe(['academic_year' => '3']);
});

test('D88 the pre-order settings come as a flag, a day and a cap; left out they are null (off on create, kept on update)', function (): void {
    $with = ProductData::fromRequest(productRequest(StoreProductRequest::class, $this->productPayload(['preorder' => ['enabled' => '1', 'expected_at' => '2026-12-01', 'limit' => '300']])));
    $empty = ProductData::fromRequest(productRequest(UpdateProductRequest::class, $this->productPayload(['preorder' => ['enabled' => false, 'expected_at' => null, 'limit' => null]])));
    $without = ProductData::fromRequest(productRequest(UpdateProductRequest::class, $this->productPayload()));

    expect($with->preorder)->toEqual(new PreorderData(true, CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'), 300))
        ->and($with->preorder?->attributes())->toEqual(['preorder_enabled' => true, 'expected_at' => CarbonImmutable::parse('2026-12-01', 'UTC'), 'preorder_limit' => 300])
        ->and($empty->preorder)->toEqual(new PreorderData(false))
        ->and($without->preorder)->toBeNull();
});

test('BR-P14 the variant shape reads the options and one sku per entry, each with its path, status, values and opening stock', function (): void {
    $basics = $this->catalogBasics();
    $sku = fn (string $code, array $values, array $extra = []): array => [
        'code' => $code, 'base_unit_id' => $basics['piece']->id, 'option_values' => $values,
        'units' => [['unit_id' => $basics['piece']->id, 'conversion_factor' => 1, 'base_price' => '10']], ...$extra,
    ];
    $payload = $this->productPayload();
    unset($payload['sku']);

    $data = ProductData::fromRequest(productRequest(UpdateProductRequest::class, [...$payload,
        'options' => [['id' => 7, 'name' => '  اللون  ', 'values' => [['value' => 'أزرق', 'image_id' => 3], ['id' => 9, 'value' => 'أسود  غامق']]]],
        'skus' => [$sku('PEN-1', ['أزرق']), $sku('PEN-2', ['أسود غامق'], ['id' => 5, 'status' => 'inactive', 'opening_stock' => 4])],
    ]));

    expect($data->options[0]->id)->toBe(7)
        ->and($data->options[0]->name)->toBe('اللون')
        ->and(array_map(fn (App\Modules\Catalog\Data\ProductOptionValueData $value): array => [$value->id, $value->value, $value->imageId], $data->options[0]->values))->toBe([[null, 'أزرق', 3], [9, 'أسود غامق', null]])
        ->and(array_map(fn (App\Modules\Catalog\Data\SkuData $sku): array => [$sku->id, $sku->code, $sku->status->value, $sku->optionValues, $sku->openingStock, $sku->path], $data->skus))->toBe([
            [null, 'PEN-1', 'active', ['أزرق'], 0, 'skus.0'],
            [5, 'PEN-2', 'inactive', ['أسود غامق'], 4, 'skus.1'],
        ]);
});

test('BR-P14 the plain sku shape is the one sku at path sku, the opening stock read on create only', function (): void {
    $created = ProductData::fromRequest(productRequest(StoreProductRequest::class, $this->productPayload(['opening_stock' => 12])));
    $updated = ProductData::fromRequest(productRequest(UpdateProductRequest::class, $this->productPayload()));

    expect($created->skus)->toHaveCount(1)
        ->and($created->skus[0]->path)->toBe('sku')
        ->and($created->skus[0]->openingStock)->toBe(12)
        ->and($created->options)->toBe([])
        ->and($updated->skus[0]->openingStock)->toBe(0);
});
