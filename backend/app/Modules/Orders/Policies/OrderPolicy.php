<?php

declare(strict_types=1);

namespace App\Modules\Orders\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Illuminate\Auth\Access\Response;

/**
 * Storefront access to an order: a customer sees and cancels only their own (BR-CU02). Someone else's order is
 * reported as not found, not forbidden, so sequential order numbers reveal nothing (D57). Whether a pending
 * status allows the cancel is BR-O02, answered by CancelOrder with `409 invalid_transition`. Merchants reach
 * every order of their tenant through the dashboard guard and tenant scope, not this policy.
 */
final readonly class OrderPolicy
{
    public function view(Customer $customer, Order $order): Response
    {
        return $this->owns($customer, $order);
    }

    public function cancel(Customer $customer, Order $order): Response
    {
        return $this->owns($customer, $order);
    }

    private function owns(Customer $customer, Order $order): Response
    {
        return $order->customer_id === $customer->id ? Response::allow() : Response::denyAsNotFound();
    }
}
