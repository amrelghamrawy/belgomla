<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\BundleComponentData;
use App\Modules\Catalog\Data\BundleData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Http\Requests\StoreBundleRequest;
use App\Modules\Catalog\Http\Requests\UpdateBundleRequest;
use App\Modules\Pricing\Data\BundlePricingData;
use App\Modules\Pricing\Enums\BundlePriceMode;

/**
 * @param  array<string, mixed>  $payload
 */
function bundleRequest(string $class, array $payload): StoreBundleRequest|UpdateBundleRequest
{
    $request = $class::create('/', 'POST', [
        'name' => '  طقم أولى  ', 'category_id' => 3, 'code' => 'SET-1', 'unit_id' => 4,
        'components' => [['sku_unit_id' => 7, 'quantity' => 2], ['sku_unit_id' => 9, 'quantity' => 1]],
        'pricing' => ['mode' => 'percent_off_sum', 'value' => 12.5],
        ...$payload,
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    return $request;
}

test('bundle data trims text, keeps the component order and normalises the pricing value', function (): void {
    $data = BundleData::fromRequest(bundleRequest(StoreBundleRequest::class, ['status' => 'inactive', 'description' => '  ', 'custom_fields' => ['grade' => 7]]));

    expect($data->name)->toBe('طقم أولى')
        ->and($data->code)->toBe('SET-1')
        ->and([$data->categoryId, $data->unitId])->toBe([3, 4])
        ->and($data->components)->toEqual([new BundleComponentData(7, 2), new BundleComponentData(9, 1)])
        ->and($data->pricing)->toEqual(new BundlePricingData(BundlePriceMode::PercentOffSum, '12.50'))
        ->and($data->description)->toBeNull()
        ->and($data->customFields)->toBe(['grade' => 7])
        ->and($data->status)->toBe(ProductStatus::Inactive);
});

test('bundle data defaults: moq 1, no status, no custom fields', function (): void {
    $data = BundleData::fromRequest(bundleRequest(StoreBundleRequest::class, []));

    expect([$data->moq, $data->status, $data->customFields])->toBe([1, null, []]);
});

test('an update carries no status, a moq when sent', function (): void {
    $data = BundleData::fromRequest(bundleRequest(UpdateBundleRequest::class, ['moq' => 3, 'pricing' => ['mode' => 'fixed', 'value' => '250']]));

    expect([$data->moq, $data->status, $data->pricing->value])->toBe([3, null, '250.00']);
});
