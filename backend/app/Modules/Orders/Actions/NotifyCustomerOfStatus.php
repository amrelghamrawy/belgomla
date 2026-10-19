<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Exceptions\MessagingRateLimited;
use App\Modules\Messaging\Services\Messenger;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Modules\Tenancy\TenantContext;
use App\Support\OrderStatusMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BR-N03: tells the customer over WhatsApp that their order moved, in the store's own words (OrderStatusMessage).
 * Nothing is sent when the store does not notify this status or the customer opted out. The message is handed to
 * Messenger only **after commit**, so a rolled-back transition sends nothing; a customer over their per-phone limit
 * (D79) is logged, never fails the transition. Called at the end of TransitionOrder and of a merchant's or the
 * system's CancelOrder.
 */
final readonly class NotifyCustomerOfStatus
{
    public function __construct(
        private CurrentTenantSettingsQuery $settings,
        private Messenger $messenger,
        private TenantContext $context,
    ) {}

    public function handle(Order $order, OrderStatus $status): void
    {
        $notifications = $this->settings->orderNotifications();

        if (! in_array($status->value, $notifications['statuses'], true)) {
            return;
        }

        $customer = Customer::query()->findOrFail($order->customer_id);

        if ($customer->order_notifications_opt_out) {
            return;
        }

        $text = OrderStatusMessage::render($status->value, $notifications['messages'][$status->value] ?? null, [
            'customer' => $customer->name,
            'store' => $notifications['store'],
            'number' => $order->number,
            'status' => $status->label(),
            'total' => $order->netTotal(),
            'reason' => $order->cancel_reason,
        ]);

        // After commit the caller may have left the tenant (a CLI loop, the demo seeder): send inside this one.
        $tenant = $this->context->get();

        DB::afterCommit(fn () => $this->context->runAs($tenant, function () use ($customer, $text, $order): void {
            try {
                $this->messenger->send(new MessagePayload($customer->phone, MessagePurpose::OrderStatus, $text, orderId: $order->id));
            } catch (MessagingRateLimited) {
                Log::warning('Order status message skipped: the customer is over the per-phone limit (D79).', ['order_id' => $order->id]);
            }
        }));
    }
}
