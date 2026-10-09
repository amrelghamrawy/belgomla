<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\BulkStatusData;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Http\Requests\BulkProductStatusRequest;
use App\Modules\Catalog\Http\Requests\ListDashboardProductsRequest;
use App\Modules\Catalog\Http\Requests\StoreProductListRequest;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;

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
        'sku' => ['code' => 'PEN-1', 'base_price' => 30, 'cover_price' => '40.5', 'base_price_percent' => '25'],
    ])));

    expect($data->name)->toBe('قلم')
        ->and($data->description)->toBeNull()
        ->and($data->customFields)->toBe(['grade' => '12'])
        ->and($data->status)->toBe(ProductStatus::ComingSoon)
        ->and($data->sku->code)->toBe('PEN-1')
        ->and($data->sku->pricing->basePrice)->toBeNull()
        ->and($data->sku->pricing->coverPrice)->toBe('40.50')
        ->and($data->sku->pricing->coverPriceGiven)->toBeTrue()
        ->and($data->sku->pricing->basePricePercent)->toBe('25.00')
        ->and($data->sku->conversionFactor)->toBe(20)
        ->and($data->sku->pricing->moq)->toBe(5)
        ->and($data->sku->pricing->tiers)->toBeNull();
});

test('BR-PR10 sku tiers are read with the percent winning over a sent price', function (): void {
    $data = ProductData::fromRequest(productRequest(StoreProductRequest::class, $this->productPayload([
        'sku' => ['tiers' => [['min_quantity' => '10', 'unit_price' => 85], ['min_quantity' => 50, 'unit_price' => '70', 'discount_percent' => 40]]],
    ])));

    expect($data->sku->pricing->tiers)->toEqual([
        new App\Modules\Pricing\Data\TierData(10, '85.00'),
        new App\Modules\Pricing\Data\TierData(50, null, '40.00'),
    ]);
});

test('sku data defaults the moq to 1 and leaves unsent prices null', function (): void {
    $payload = $this->productPayload(['sku' => ['cover_price' => null]]);
    unset($payload['sku']['moq']);

    $data = ProductData::fromRequest(productRequest(StoreProductRequest::class, $payload));

    expect($data->sku->pricing->moq)->toBe(1)
        ->and($data->sku->pricing->coverPrice)->toBeNull()
        ->and($data->sku->pricing->basePrice)->toBe('90.00')
        ->and($data->sku->pricing->basePricePercent)->toBeNull()
        ->and($data->status)->toBeNull();
});

test('sku data maps to the skus identity columns; prices are left to pricing', function (): void {
    $data = ProductData::fromRequest(productRequest(UpdateProductRequest::class, $this->productPayload()));

    expect($data->sku->attributes())->toBe([
        'code' => 'ADW-SCI-P1-T1',
        'selling_unit_id' => $this->catalogBasics()['carton']->id,
        'base_unit_id' => $this->catalogBasics()['piece']->id,
        'conversion_factor' => 20,
    ])->and($data->status)->toBeNull();
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
