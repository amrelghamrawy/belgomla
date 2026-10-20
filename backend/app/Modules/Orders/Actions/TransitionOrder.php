<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Contracts\CollectsPayments;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStateMachine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/orders/{number}/transition: the merchant moves an order forward (BR-O01). Owns its
 * transaction: the order row is locked and re-read so two clicks cannot both pass the guard; `delivered`
 * deducts the stock (BR-INV05) — only what was delivered when the merchant records a partial delivery, the rest
 * released and the totals recomputed (BR-O13, D86, audited as `delivered_short`) — sets the due date from the customer's payment terms (BR-C06) and, with `collect_full_amount`, records the remaining balance as collected
 * through CollectsPayments (BR-C04); history and the `order.status_changed` audit record are written last, then the
 * customer is told after commit (BR-N03).
 */
final readonly class TransitionOrder
{
    public function __construct(
        private OrderStateMachine $machine,
        private ApplyTransition $apply,
        private CollectsPayments $payments,
        private NotifyCustomerOfStatus $notify,
        private AuditLogger $audit,
    ) {}

    public function handle(Order $order, TransitionData $data, ?int $actorId = null): Order
    {
        return DB::transaction(function () use ($order, $data, $actorId): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $plan = $this->machine->plan($order, $data->to);

            $short = $this->apply->handle($order, $plan, $actorId === null ? ActorType::System : ActorType::TenantUser, $actorId, $data->note, $this->dueDate($order, $plan->to), $data->deliveredQuantities);

            if ($data->collectFullAmount && $plan->to === OrderStatus::Delivered) {
                $this->payments->collectRemaining($order, $actorId);
            }

            $this->audit->log('order.status_changed', $order, ['status' => $plan->from->value], [
                'status' => $plan->to->value,
                ...($data->note === null ? [] : ['note' => $data->note]),
                ...($short === [] ? [] : ['delivered_short' => $short, 'order_total' => $order->order_total]),
            ]);

            $this->notify->handle($order, $plan->to);

            return $order;
        });
    }

    /**
     * BR-C06: a delivered order falls due its customer's payment terms later (a snapshot: later changes to the terms
     * do not move it); without terms it is due on delivery.
     *
     * @return array<string, CarbonImmutable>
     */
    private function dueDate(Order $order, OrderStatus $to): array
    {
        if ($to !== OrderStatus::Delivered) {
            return [];
        }

        $terms = Customer::query()->whereKey($order->customer_id)->value('payment_terms_days');

        return ['due_at' => CarbonImmutable::now()->addDays(is_numeric($terms) ? (int) $terms : 0)];
    }
}
