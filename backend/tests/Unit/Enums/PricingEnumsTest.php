<?php

declare(strict_types=1);

use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Pricing\Enums\PriceSource;

test('BR-PR08 D85 a discount is an amount or a percent, each with an arabic label', function (): void {
    expect(DiscountType::toArray())->toBe(['amount' => 'مبلغ', 'percent' => 'نسبة']);
});

test('BR-PR14 a price comes from the base price, a tier, the group or the customer, each with an arabic label', function (): void {
    expect(PriceSource::toArray())->toBe(['base' => 'السعر الأساسي', 'tier' => 'سعر الكمية', 'group' => 'سعر المستوى', 'customer' => 'سعر خاص بالعميل']);
});

test('BR-PR14 only group and negotiated prices are the customer\'s own', function (PriceSource $source, bool $own): void {
    expect($source->isCustomerSpecific())->toBe($own);
})->with([
    'base' => [PriceSource::Base, false],
    'tier' => [PriceSource::Tier, false],
    'group' => [PriceSource::Group, true],
    'customer' => [PriceSource::Customer, true],
]);
