<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Contracts\CollectsPayments;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/orders/{number}/transition: the merchant moves an order forward (BR-O01). Owns its
 * transaction: the order row is locked and re-read so two clicks cannot both pass the guard; `delivered`
 * deducts the stock (BR-INV05) and, with `collect_full_amount`, records the remaining balance as collected
 * through CollectsPayments (BR-C04); history and the `order.status_changed` audit record are written last.
 */
final readonly class TransitionOrder
{
    public function __construct(
        private OrderStateMachine $machine,
        private ApplyTransition $apply,
        private CollectsPayments $payments,
        private AuditLogger $audit,
    ) {}

    public function handle(Order $order, TransitionData $data, ?int $actorId = null): Order
    {
        return DB::transaction(function () use ($order, $data, $actorId): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $plan = $this->machine->plan($order, $data->to);

            $this->apply->handle($order, $plan, $actorId === null ? ActorType::System : ActorType::TenantUser, $actorId, $data->note);

            if ($data->collectFullAmount && $plan->to === OrderStatus::Delivered) {
                $this->payments->collectRemaining($order, $actorId);
            }

            $this->audit->log('order.status_changed', $order, ['status' => $plan->from->value], array_filter([
                'status' => $plan->to->value,
                'note' => $data->note,
            ], fn (?string $value): bool => $value !== null));

            return $order;
        });
    }
}
