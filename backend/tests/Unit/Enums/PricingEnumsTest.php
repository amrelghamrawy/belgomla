<?php

declare(strict_types=1);

use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Pricing\Enums\PriceSource;
use App\Modules\Pricing\Enums\PromotionStatus;

test('BR-PR08 D85 a discount is an amount or a percent, each with an arabic label', function (): void {
    expect(DiscountType::toArray())->toBe(['amount' => 'مبلغ', 'percent' => 'نسبة']);
});

test('BR-PR14 BR-PR15 a price comes from the base price, a tier, the group, the customer or a promotion, each with an arabic label', function (): void {
    expect(PriceSource::toArray())->toBe(['base' => 'السعر الأساسي', 'tier' => 'سعر الكمية', 'group' => 'سعر المستوى', 'customer' => 'سعر خاص بالعميل', 'promotion' => 'سعر العرض']);
});

test('BR-PR14 only group and negotiated prices are the customer\'s own', function (PriceSource $source, bool $own): void {
    expect($source->isCustomerSpecific())->toBe($own);
})->with([
    'base' => [PriceSource::Base, false],
    'tier' => [PriceSource::Tier, false],
    'group' => [PriceSource::Group, true],
    'customer' => [PriceSource::Customer, true],
]);

test('BR-PR15 a price can also come from a promotion, which is not the customer\'s own', function (): void {
    expect(PriceSource::Promotion->label())->toBe('سعر العرض')
        ->and(PriceSource::Promotion->isCustomerSpecific())->toBeFalse();
});

test('BR-PR15 a promotion is a draft, scheduled, active, ended or cancelled, each with an arabic label', function (): void {
    expect(PromotionStatus::toArray())->toBe(['draft' => 'مسودة', 'scheduled' => 'مجدول', 'active' => 'جارٍ', 'ended' => 'انتهى', 'cancelled' => 'ملغي']);
});

test('D90 f which promotions can be edited, cancelled or be live', function (PromotionStatus $status, bool $editable, bool $cancellable, bool $mayBeLive): void {
    expect([$status->isEditable(), $status->canBeCancelled(), $status->mayBeLive()])->toBe([$editable, $cancellable, $mayBeLive]);
})->with([
    'draft' => [PromotionStatus::Draft, true, true, false],
    'scheduled' => [PromotionStatus::Scheduled, true, true, true],
    'active' => [PromotionStatus::Active, false, true, true],
    'ended' => [PromotionStatus::Ended, false, false, false],
    'cancelled' => [PromotionStatus::Cancelled, false, false, false],
]);

test('BR-P15 a bundle is priced fixed or at the components\' sum less a %, each with an arabic label', function (): void {
    expect(BundlePriceMode::toArray())->toBe(['fixed' => 'سعر ثابت', 'percent_off_sum' => 'خصم من مجموع الأصناف']);
});
