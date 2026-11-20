<?php

declare(strict_types=1);

use App\Modules\Pricing\Data\BulkPricingData;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Data\PromotionData;
use App\Modules\Pricing\Data\PromotionPrice;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Http\Requests\BulkPricingRequest;
use App\Modules\Pricing\Http\Requests\StorePromotionRequest;
use App\Modules\Pricing\Http\Requests\UpdatePromotionRequest;
use App\Modules\Pricing\Http\Requests\UpdateSkuPricingRequest;
use Carbon\CarbonImmutable;

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

test('BR-PR14 a pricing context looks up a negotiated price by sku and ignores a 0% group', function (): void {
    $context = new PricingContext('0.00', [5 => '70.00']);

    expect($context->customerPrice(5))->toBe('70.00')
        ->and($context->customerPrice(6))->toBeNull()
        ->and($context->groupDiscount())->toBeNull()
        ->and(new PricingContext('12.50')->groupDiscount())->toBe('12.50')
        ->and(new PricingContext()->groupDiscount())->toBeNull();
});

test('BR-PR15 D60 promotion data reads Cairo wall times as utc, the % normalised, and the filter', function (): void {
    $data = PromotionData::fromRequest(StorePromotionRequest::create('/', 'POST', [
        'name' => ' عرض الأضواء ',
        'discount_percent' => '15',
        'starts_at' => '2026-11-01T00:00',
        'ends_at' => '2026-11-30T23:59:00+02:00',
        'filter' => ['q' => 'الأضواء', 'category' => '4'],
        'status' => 'draft',
    ]));

    expect($data->name)->toBe('عرض الأضواء')
        ->and($data->discountPercent)->toBe('15.00')
        ->and($data->startsAt?->toIso8601String())->toBe('2026-10-31T22:00:00+00:00')
        ->and($data->endsAt?->toIso8601String())->toBe('2026-11-30T21:59:00+00:00')
        ->and($data->filter?->q)->toBe('الأضواء')
        ->and($data->filter?->categoryId)->toBe(4)
        ->and($data->skuIds)->toBeNull()
        ->and($data->status)->toBe(PromotionStatus::Draft);
});

test('BR-PR15 a patch reads only what was sent; a sku list replaces the target', function (): void {
    $data = PromotionData::fromRequest(UpdatePromotionRequest::create('/', 'PATCH', ['sku_ids' => ['7', 9]]));

    expect([$data->name, $data->discountPercent, $data->startsAt, $data->endsAt, $data->filter, $data->status])->toBe([null, null, null, null, null, null])
        ->and($data->skuIds)->toBe([7, 9]);
});

test('BR-PR15 the promotion summary the storefront shows', function (): void {
    $promotion = new PromotionPrice(3, 'عرض', '15.00', CarbonImmutable::parse('2026-11-30 22:00:00'));

    expect($promotion->summary())->toBe(['id' => 3, 'name' => 'عرض', 'discount_percent' => '15.00', 'ends_at' => '2026-11-30T22:00:00+00:00']);
});

test('BR-PR15 the pricing context answers the promotion of a sku', function (): void {
    $promotion = new PromotionPrice(3, 'عرض', '15.00', CarbonImmutable::parse('2026-11-30 22:00:00'));
    $context = new PricingContext(promotions: [7 => $promotion]);

    expect($context->promotion(7))->toBe($promotion)
        ->and($context->promotion(8))->toBeNull();
});
