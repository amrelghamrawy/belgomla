<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;

/** BR-O04: appends one status change with its actor and optional note. Joins the caller's transaction. */
final readonly class RecordStatusChange
{
    public function handle(Order $order, ?OrderStatus $from, ActorType $actorType, ?int $actorId, ?string $note = null): OrderStatusHistory
    {
        return OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $order->status,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'note' => $note,
        ]);
    }
}
