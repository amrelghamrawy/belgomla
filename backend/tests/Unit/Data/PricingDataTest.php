<?php

declare(strict_types=1);

use App\Modules\Pricing\Data\BulkPricingData;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Http\Requests\BulkPricingRequest;
use App\Modules\Pricing\Http\Requests\UpdateSkuPricingRequest;

test('sku pricing data normalises money and reads every tier', function (): void {
    $data = SkuPricingData::fromRequest(UpdateSkuPricingRequest::create('/', 'PUT', [
        'cover_price' => '120.5', 'base_price' => 90, 'moq' => '5',
        'tiers' => [['min_quantity' => '10', 'unit_price' => 85], ['min_quantity' => 50, 'discount_percent' => '35']],
    ]));

    expect($data->coverPrice)->toBe('120.50')
        ->and($data->coverPriceGiven)->toBeTrue()
        ->and($data->basePrice)->toBe('90.00')
        ->and($data->basePricePercent)->toBeNull()
        ->and($data->moq)->toBe(5)
        ->and($data->tiers)->toEqual([new TierData(10, '85.00'), new TierData(50, null, '35.00')]);
});

test('BR-PR10 a sent percent wins over a sent price, for the base price and per tier', function (): void {
    $data = SkuPricingData::fromRequest(UpdateSkuPricingRequest::create('/', 'PUT', [
        'base_price' => '90', 'base_price_percent' => '25', 'moq' => 1,
        'tiers' => [['min_quantity' => 10, 'unit_price' => '80', 'discount_percent' => '30']],
    ]));

    expect($data->basePrice)->toBeNull()
        ->and($data->basePricePercent)->toBe('25.00')
        ->and($data->tiers)->toEqual([new TierData(10, null, '30.00')]);
});

test('a cover price that is not sent is kept; a null one is cleared', function (): void {
    $absent = SkuPricingData::fromRequest(UpdateSkuPricingRequest::create('/', 'PUT', ['base_price' => '90', 'moq' => 1, 'tiers' => []]));
    $cleared = SkuPricingData::fromRequest(UpdateSkuPricingRequest::create('/', 'PUT', ['cover_price' => null, 'base_price' => '90', 'moq' => 1, 'tiers' => []]));

    expect($absent->coverPriceGiven)->toBeFalse()
        ->and($absent->tiers)->toBe([])
        ->and($cleared->coverPriceGiven)->toBeTrue()
        ->and($cleared->coverPrice)->toBeNull();
});

test('bulk pricing data reads the nested filter, the percents and an optional moq', function (): void {
    $data = BulkPricingData::fromRequest(BulkPricingRequest::create('/', 'POST', [
        'filter' => ['f' => ['publisher' => 3], 'q' => ' علوم '],
        'base_price_percent' => '25',
        'tiers' => [['min_quantity' => 10, 'discount_percent' => 30]],
        'moq' => '6',
    ]));

    expect($data->filter->fields)->toBe(['publisher' => '3'])
        ->and($data->filter->q)->toBe('علوم')
        ->and($data->basePricePercent)->toBe('25.00')
        ->and($data->tiers)->toEqual([new TierData(10, null, '30.00')])
        ->and($data->moq)->toBe(6);
});

test('bulk pricing data leaves the moq null and the tiers empty when not sent', function (): void {
    $data = BulkPricingData::fromRequest(BulkPricingRequest::create('/', 'POST', ['filter' => ['q' => 'x'], 'base_price_percent' => '25']));

    expect($data->moq)->toBeNull()
        ->and($data->tiers)->toBe([]);
});
