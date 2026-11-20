<?php

declare(strict_types=1);

namespace App\Modules\Collections\Queries;

use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Contracts\CustomerExposure;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * D77: a customer's exposure for the credit limit (BR-C05) = what they owe on delivered orders (net of returns and
 * collections, D59) + the net totals of their open orders (pending … out for delivery: their stock is already held for
 * them) − the credit they still have. Orders' CustomerExposure.
 */
final readonly class CustomerExposureQuery implements CustomerExposure
{
    private const array OPEN = [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::OutForDelivery];

    public function of(Customer $customer): string
    {
        $owed = Order::query()
            ->where('customer_id', $customer->id)
            ->where('status', OrderStatus::Delivered)
            ->whereRaw('order_total - returned_total - collected_amount > 0')
            ->sum(DB::raw('order_total - returned_total - collected_amount'));

        $open = Order::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', self::OPEN)
            ->sum(DB::raw('order_total - returned_total'));

        return Money::subtract(Money::add($this->money($owed), $this->money($open)), $this->credit($customer));
    }

    /** BR-C07: what is left of the customer's live credits. */
    public function credit(Customer $customer): string
    {
        return $this->money(CustomerCredit::query()->where('customer_id', $customer->id)->whereNull('voided_at')->sum('remaining'));
    }

    private function money(mixed $sum): string
    {
        return Money::add('0.00', is_numeric($sum) ? (string) $sum : '0');
    }
}
