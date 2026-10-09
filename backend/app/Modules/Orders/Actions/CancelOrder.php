<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Data\CancelData;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\CannotCancel;
use App\Modules\Orders\Exceptions\OrderHasCollections;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/store|dashboard/orders/{number}/cancel (BR-O02): the customer cancels a pending order, the merchant
 * any order not delivered or cancelled yet; never while money is collected on it (BR-O03, D7). Owns its
 * transaction: locks the order, releases the reservation (BR-INV04), stores reason and canceller, appends the
 * history, tells the merchant when the customer cancelled (BR-N02) and audits `order.cancelled`.
 */
final readonly class CancelOrder
{
    public function __construct(
        private OrderStateMachine $machine,
        private ApplyTransition $apply,
        private NotifyMerchants $notify,
        private AuditLogger $audit,
    ) {}

    public function handle(Order $order, CancelData $data, CancelledBy $by, ?int $actorId = null): Order
    {
        return DB::transaction(function () use ($order, $data, $by, $actorId): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertCancellable($order, $by);
            $plan = $this->machine->plan($order, OrderStatus::Cancelled);

            $this->apply->handle($order, $plan, $by->actorType(), $actorId, $data->reason, [
                'cancel_reason' => $data->reason,
                'cancelled_by_type' => $by,
            ]);

            if ($by === CancelledBy::Customer) {
                $this->notify->handle(NotificationType::CustomerCancelled, $order);
            }

            $this->audit->log('order.cancelled', $order, ['status' => $plan->from->value], [
                'status' => OrderStatus::Cancelled->value,
                'cancel_reason' => $data->reason,
                'cancelled_by' => $by->value,
            ]);

            return $order;
        });
    }

    private function assertCancellable(Order $order, CancelledBy $by): void
    {
        if ($order->status->isTerminal()) {
            throw CannotCancel::terminal($order->status);
        }

        if ($by === CancelledBy::Customer && $order->status !== OrderStatus::Pending) {
            throw CannotCancel::byCustomer($order->status);
        }

        if (Money::compare($order->collected_amount, '0.00') > 0) {
            throw OrderHasCollections::amount($order->collected_amount);
        }
    }
}
