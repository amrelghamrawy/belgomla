<?php

declare(strict_types=1);

use App\Modules\Pricing\Enums\DiscountType;

test('BR-PR08 D85 a discount is an amount or a percent, each with an arabic label', function (): void {
    expect(DiscountType::toArray())->toBe(['amount' => 'مبلغ', 'percent' => 'نسبة']);
});
