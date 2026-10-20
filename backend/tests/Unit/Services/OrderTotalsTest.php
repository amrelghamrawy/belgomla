<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderTotals;
use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Pricing\Exceptions\DiscountTooLarge;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->order = Order::factory()->create(['items_total' => '500.00', 'order_total' => '500.00']);
    $this->lines = [
        OrderItem::factory()->for($this->order)->make(['line_total' => '300.00']),
        OrderItem::factory()->for($this->order)->make(['line_total' => '60.50']),
    ];
    $this->discount = fn (DiscountType $type, string $value) => $this->order->forceFill(['discount_type' => $type, 'discount_value' => $value, 'discount_reason' => 'عميل دائم']);
});

test('BR-O08 the items total is the sum of the lines given and the order total follows it', function (): void {
    app(OrderTotals::class)->apply($this->order, $this->lines);

    expect($this->order->only(['items_total', 'discount_amount', 'order_total']))->toBe(['items_total' => '360.50', 'discount_amount' => '0.00', 'order_total' => '360.50'])
        ->and($this->order->isDirty('items_total'))->toBeTrue();
});

test('D85 a percent order discount is taken off the new items total', function (): void {
    ($this->discount)(DiscountType::Percent, '10');

    app(OrderTotals::class)->apply($this->order, $this->lines);

    expect($this->order->only(['discount_amount', 'order_total']))->toBe(['discount_amount' => '36.05', 'order_total' => '324.45']);
});

test('D85 an EGP order discount keeps its value', function (): void {
    ($this->discount)(DiscountType::Amount, '50');

    app(OrderTotals::class)->apply($this->order, $this->lines);

    expect($this->order->only(['discount_amount', 'order_total']))->toBe(['discount_amount' => '50.00', 'order_total' => '310.50']);
});

test('D85 an edit refuses an order discount that no longer fits', function (): void {
    ($this->discount)(DiscountType::Amount, '400');

    app(OrderTotals::class)->apply($this->order, $this->lines);
})->throws(DiscountTooLarge::class);

test('D86 a delivery caps an order discount that no longer fits, leaving 0.01', function (): void {
    ($this->discount)(DiscountType::Amount, '400');

    app(OrderTotals::class)->apply($this->order, $this->lines, capDiscount: true);

    expect($this->order->only(['discount_amount', 'order_total']))->toBe(['discount_amount' => '360.49', 'order_total' => '0.01']);
});
