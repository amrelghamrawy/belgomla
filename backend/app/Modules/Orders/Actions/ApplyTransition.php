<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Data\TransitionPlan;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;

/**
 * Carries out an OrderStateMachine plan on a locked order, the shared step of TransitionOrder and CancelOrder
 * (07-domain-model.md §11): moves the stock — release on cancel (BR-INV04), deduct with a `sale` movement on
 * delivery (BR-INV05) — after locking every SKU of the order in sku_id order, then saves the status and its
 * timestamps and appends the history entry (BR-O04). Joins the caller's transaction (Tech §11).
 */
final readonly class ApplyTransition
{
    public function __construct(
        private StockLedger $ledger,
        private RecordStatusChange $history,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  further order columns to save with the status (cancel reason …)
     */
    public function handle(Order $order, TransitionPlan $plan, ActorType $actorType, ?int $actorId, ?string $note = null, array $attributes = []): void
    {
        if ($plan->releasesStock || $plan->deductsStock) {
            $this->moveStock($order, $plan, $actorType === ActorType::TenantUser ? $actorId : null);
        }

        $order->forceFill([
            ...$attributes,
            ...array_fill_keys($plan->timestamps, now()),
            'status' => $plan->to,
        ])->save();

        $this->history->handle($order, $plan->from, $actorType, $actorId, $note);
    }

    private function moveStock(Order $order, TransitionPlan $plan, ?int $tenantUserId): void
    {
        $items = $order->items()->with('sku')->get();
        $this->ledger->lock(array_values($items->map(fn (OrderItem $item): int => $item->sku_id)->all()));

        foreach ($items as $item) {
            if ($plan->deductsStock) {
                $this->ledger->deduct($item->sku, $item->quantity_base, $order->id, $tenantUserId);
            } else {
                $this->ledger->release($item->sku, $item->quantity_base);
            }
        }
    }
}
