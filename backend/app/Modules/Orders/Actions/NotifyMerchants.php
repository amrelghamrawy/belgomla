<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;

/**
 * BR-N02: tells every owner of the tenant about a new order or a customer cancellation, as a row in our own
 * `notifications` table (07-domain-model.md §9; an Action, not a Laravel Notification, because it is a plain
 * write inside the order's transaction). Joins the caller's transaction.
 */
final readonly class NotifyMerchants
{
    public function handle(NotificationType $type, Order $order): MerchantNotification
    {
        return MerchantNotification::query()->create([
            'tenant_user_id' => null,
            'type' => $type,
            'data' => [
                'order_number' => $order->number,
                'customer_name' => $order->delivery_name,
                'order_total' => $order->order_total,
                ...($type === NotificationType::CustomerCancelled ? ['reason' => $order->cancel_reason] : []),
            ],
        ]);
    }
}
