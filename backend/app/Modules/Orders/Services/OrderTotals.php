<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Pricing\Services\DiscountCalculator;
use App\Support\Money;

/**
 * BR-O08 after the lines changed (EditOrderLines, BR-O12) or were delivered short (ApplyTransition, BR-O13): fills the
 * order's `items_total` (Σ line_total of the live lines), `discount_amount` and `order_total = items_total −
 * discount_amount` without saving. A % order discount is taken off the new items total again; an EGP one keeps its
 * value. When it no longer fits, an edit is refused (`422 discount_too_large`, D85) while a delivery, which already
 * happened, caps it so the order still costs 0.01 (D86).
 */
final readonly class OrderTotals
{
    public function __construct(private DiscountCalculator $calculator) {}

    /**
     * @param  iterable<OrderItem>  $lines  the live lines, as they are now
     */
    public function apply(Order $order, iterable $lines, bool $capDiscount = false): void
    {
        $itemsTotal = '0.00';

        foreach ($lines as $line) {
            $itemsTotal = Money::add($itemsTotal, $line->line_total);
        }

        $amount = $this->discountAmount($order, $itemsTotal, $capDiscount);

        $order->forceFill([
            'items_total' => $itemsTotal,
            'discount_amount' => $amount,
            'order_total' => Money::subtract($itemsTotal, $amount),
        ]);
    }

    private function discountAmount(Order $order, string $itemsTotal, bool $cap): string
    {
        if ($order->discount_type === null || $order->discount_value === null) {
            return '0.00';
        }

        return $cap
            ? $this->calculator->cappedOrderAmount($itemsTotal, $order->discount_type, $order->discount_value)
            : $this->calculator->orderAmount($itemsTotal, $order->discount_type, $order->discount_value);
    }
}
