<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;

/**
 * BR-N02: tells every owner of the tenant about something in the dashboard, as a row in our own `notifications`
 * table (07-domain-model.md §9; an Action, not a Laravel Notification, because it is a plain write inside the
 * transaction of the change it reports): a new order or a customer cancellation (`forOrder`), or a store number that
 * dropped (`whatsapp_dropped` with the phone and reason, D70). Joins the caller's transaction.
 */
final readonly class NotifyMerchants
{
    /**
     * @param  array<string, string|int|null>  $data
     */
    public function handle(NotificationType $type, array $data): MerchantNotification
    {
        return MerchantNotification::query()->create([
            'tenant_user_id' => null,
            'type' => $type,
            'data' => $data,
        ]);
    }

    /** The order's number, customer and total, plus the reason of a customer cancellation. */
    public function forOrder(NotificationType $type, Order $order): MerchantNotification
    {
        return $this->handle($type, [
            'order_number' => $order->number,
            'customer_name' => $order->delivery_name,
            'order_total' => $order->order_total,
            ...($type === NotificationType::CustomerCancelled ? ['reason' => $order->cancel_reason] : []),
        ]);
    }
}
