<?php

declare(strict_types=1);

namespace App\Modules\Orders\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Enums\Permission;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Queries\CurrentPermissionsQuery;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Storefront access to an order: a customer sees and cancels only their own (BR-CU02). Someone else's order is
 * reported as not found, not forbidden, so sequential order numbers reveal nothing (D57). Whether a pending
 * status allows the cancel is BR-O02, answered by CancelOrder with `409 invalid_transition`. Merchants reach
 * every order of their tenant through the dashboard guard, the tenant scope and the `orders.manage` permission;
 * on top of it (D82) the warehouse role moves orders only to preparing / out for delivery and never cancels, and
 * collecting the full amount on delivery needs `collections.manage`.
 */
final readonly class OrderPolicy
{
    private const array WAREHOUSE_TARGETS = [OrderStatus::Preparing, OrderStatus::OutForDelivery];

    public function __construct(private CurrentPermissionsQuery $permissions) {}

    public function transition(Authenticatable $user, Order $order, OrderStatus $to, bool $collects): Response
    {
        if ($user instanceof Customer
            || ($collects && $to === OrderStatus::Delivered && ! $this->permissions->has(Permission::CollectionsManage))
            || ($this->permissions->role() === TenantUserRole::Warehouse && ! in_array($to, self::WAREHOUSE_TARGETS, true))) {
            return Response::deny();
        }

        return Response::allow();
    }

    public function cancelFromDashboard(Authenticatable $user): Response
    {
        return $user instanceof Customer || $this->permissions->role() === TenantUserRole::Warehouse ? Response::deny() : Response::allow();
    }

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
