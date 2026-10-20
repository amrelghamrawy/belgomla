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
 * system's CancelOrder, by PlaceOrderForCustomer (`placed`) when the merchant asks, by ApplyOrderDiscount (`discount`), by EditOrderLines
 * (`edited`) and by ConvertPreorders (`preorder_converted`).
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

        $this->send($order, $status->value, $status->label(), $notifications);
    }

    /**
     * BR-O11: a merchant placed the order for the customer and asked to tell them (the `placed` text). The store's
     * notified statuses do not apply, the customer's opt-out does.
     */
    public function placed(Order $order): void
    {
        $this->send($order, 'placed', $order->status->label(), $this->settings->orderNotifications());
    }

    /**
     * BR-O14, D88: the merchant converted (part of) the customer's pre-order into this normal order (ConvertPreorders): the
     * `preorder_converted` text with the new order's number and total. Like `placed`, the store's notified statuses do
     * not apply (the customer must know their order exists), the customer's opt-out does.
     */
    public function preorderConverted(Order $order): void
    {
        $this->send($order, 'preorder_converted', $order->status->label(), $this->settings->orderNotifications());
    }

    /**
     * D85: the merchant changed the order's discounts (ApplyOrderDiscount). Sent only when the store notifies
     * `discount` (off by default), with the new total.
     */
    public function discounted(Order $order): void
    {
        $this->event($order, 'discount');
    }

    /**
     * D87: the merchant changed the order's lines (EditOrderLines). Sent when the store notifies `edited` (on by
     * default), with the new total.
     */
    public function edited(Order $order): void
    {
        $this->event($order, 'edited');
    }

    /** An order event other than a status, sent only when the store notifies it. */
    private function event(Order $order, string $event): void
    {
        $notifications = $this->settings->orderNotifications();

        if (in_array($event, $notifications['statuses'], true)) {
            $this->send($order, $event, $order->status->label(), $notifications);
        }
    }

    /**
     * @param  array{statuses: list<string>, messages: array<string, string|null>, store: string}  $notifications
     */
    private function send(Order $order, string $message, string $statusLabel, array $notifications): void
    {
        $customer = Customer::query()->findOrFail($order->customer_id);

        if ($customer->order_notifications_opt_out) {
            return;
        }

        $text = OrderStatusMessage::render($message, $notifications['messages'][$message] ?? null, [
            'customer' => $customer->name,
            'store' => $notifications['store'],
            'number' => $order->number,
            'status' => $statusLabel,
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
