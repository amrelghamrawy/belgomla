<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Data\TransitionPlan;
use App\Modules\Orders\Enums\OrderItemChangeType;
use App\Modules\Orders\Exceptions\OrderEditRefused;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderItemChange;
use App\Modules\Orders\Services\OrderTotals;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * Carries out an OrderStateMachine plan on a locked order, the shared step of TransitionOrder and CancelOrder
 * (07-domain-model.md §11): moves the stock of the live lines (BR-O12) — release on cancel (BR-INV04), deduct with a
 * `sale` movement on delivery (BR-INV05) — after locking every SKU of the order in sku_id order, then saves the status
 * and its timestamps and appends the history entry (BR-O04). Joins the caller's transaction (Tech §11).
 *
 * BR-O13, D86: a delivery may name what each line actually delivered (order item id => selling units, at most what was
 * ordered; a line left out went out in full). The delivered units are deducted and the rest released; each line keeps
 * its `delivered_quantity` and is charged for it, every short line is written as a `delivered_short` change, the
 * totals are recomputed (OrderTotals, an EGP order discount capped) and the collection status derived again; what was
 * already collected must still fit (`409 below_collected`). The history note defaults to «تسليم جزئي».
 *
 * A bundle (BR-P15, V2 Task 15, D93) moves the stock of its component lines; a partial delivery names the bundle line
 * with the bundles delivered (its component lines cannot be named apart), each component line delivering that many
 * bundles' worth and keeping its share per bundle; the bundle line records the short delivery.
 */
final readonly class ApplyTransition
{
    public const string PARTIAL_NOTE = 'تسليم جزئي';

    public function __construct(
        private StockLedger $ledger,
        private RecordStatusChange $history,
        private OrderTotals $totals,
        private ApplyCollectedAmount $collected,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  further order columns to save with the status (cancel reason …)
     * @param  array<int, int>|null  $deliveredQuantities  order item id => delivered selling units, on delivery only
     * @return list<array{order_item_id: int, ordered: int, delivered: int}> the lines delivered short
     */
    public function handle(Order $order, TransitionPlan $plan, ActorType $actorType, ?int $actorId, ?string $note = null, array $attributes = [], ?array $deliveredQuantities = null): array
    {
        $short = [];

        if ($plan->releasesStock || $plan->deductsStock) {
            $short = $this->moveStock($order, $plan, $actorType === ActorType::TenantUser ? $actorId : null, $deliveredQuantities ?? []);
        }

        $order->forceFill([
            ...$attributes,
            ...array_fill_keys($plan->timestamps, now()),
            'status' => $plan->to,
        ])->save();

        if ($short !== []) {
            $this->collected->handle($order, $order->collected_amount);
        }

        $this->history->handle($order, $plan->from, $actorType, $actorId, $note ?? ($short === [] ? null : self::PARTIAL_NOTE));

        return $short;
    }

    /**
     * @param  array<int, int>  $deliveredQuantities
     * @return list<array{order_item_id: int, ordered: int, delivered: int}>
     */
    private function moveStock(Order $order, TransitionPlan $plan, ?int $tenantUserId, array $deliveredQuantities): array
    {
        /** @var Collection<int, OrderItem> $items */
        $items = $order->liveItems()->where('reserved', true)->with('sku')->get()->keyBy('id'); // a pre-order line holds nothing (BR-O14)
        /** @var Collection<int, OrderItem> $bundles */
        $bundles = $order->liveItems()->where('is_bundle', true)->get()->keyBy('id'); // D93: their component lines hold the stock
        $this->ledger->lock(array_values($items->map(fn (OrderItem $item): int => $item->sku_id)->all()));

        if (! $plan->deductsStock) {
            foreach ($items as $item) {
                $this->ledger->release($item->sku, $item->quantity_base);
            }

            return [];
        }

        $delivered = $this->delivered($items, $bundles, $deliveredQuantities);
        $short = [];

        foreach ($bundles as $bundle) {
            $short = [...$short, ...$this->deliverBundle($order, $bundle, $delivered[$bundle->id], $tenantUserId)];
        }

        foreach ($items as $item) {
            $units = $delivered[$item->id];
            $deducted = $units * $item->conversion_factor;

            if ($deducted > 0) {
                $this->ledger->deduct($item->sku, $deducted, $order->id, $tenantUserId);
            }

            if ($deducted < $item->quantity_base) {
                $this->ledger->release($item->sku, $item->quantity_base - $deducted);
            }

            if ($item->parent_item_id !== null) {
                $parent = $bundles->get($item->parent_item_id);
                $perBundle = $parent instanceof OrderItem ? bcdiv($item->line_total, (string) max(1, $parent->quantity), 2) : $item->unit_price;
                $item->forceFill(['delivered_quantity' => $units, 'line_total' => Money::times($perBundle, $parent instanceof OrderItem ? $delivered[$parent->id] : $units)])->save();

                continue;
            }

            if ($deducted < $item->quantity_base) {
                $short[] = ['order_item_id' => $item->id, 'ordered' => $item->quantity, 'delivered' => $units];
                OrderItemChange::query()->create([
                    'order_id' => $order->id, 'order_item_id' => $item->id, 'change' => OrderItemChangeType::DeliveredShort,
                    'old_quantity' => $item->quantity, 'new_quantity' => $units,
                    'unit_price_before' => $item->unit_price, 'unit_price_after' => $item->unit_price, 'actor_id' => $tenantUserId,
                ]);
            }

            $item->forceFill(['delivered_quantity' => $units, 'line_total' => Money::times($item->unit_price, $units)])->save();
        }

        if ($short !== []) {
            $this->recomputeTotals($order, $items->concat($bundles));
        }

        return $short;
    }

    /**
     * D93: the bundle line keeps the bundles delivered and is charged for them; delivered short, it records the change.
     *
     * @return list<array{order_item_id: int, ordered: int, delivered: int}>
     */
    private function deliverBundle(Order $order, OrderItem $bundle, int $units, ?int $tenantUserId): array
    {
        $bundle->forceFill(['delivered_quantity' => $units, 'line_total' => Money::times($bundle->unit_price, $units)])->save();

        if ($units === $bundle->quantity) {
            return [];
        }

        OrderItemChange::query()->create([
            'order_id' => $order->id, 'order_item_id' => $bundle->id, 'change' => OrderItemChangeType::DeliveredShort,
            'old_quantity' => $bundle->quantity, 'new_quantity' => $units,
            'unit_price_before' => $bundle->unit_price, 'unit_price_after' => $bundle->unit_price, 'actor_id' => $tenantUserId,
        ]);

        return [['order_item_id' => $bundle->id, 'ordered' => $bundle->quantity, 'delivered' => $units]];
    }

    /**
     * BR-O13: every line's delivered units, the ordered quantity unless given; a line of another order, more than was
     * ordered, or nothing delivered at all is refused (422, the order is cancelled instead, D86).
     *
     * D93: a bundle line is given in bundles; its component lines follow it (quantity per bundle × the bundles
     * delivered) and cannot be given themselves.
     *
     * @param  Collection<int, OrderItem>  $items
     * @param  Collection<int, OrderItem>  $bundles
     * @param  array<int, int>  $given
     * @return array<int, int> order item id => delivered selling units
     */
    private function delivered(Collection $items, Collection $bundles, array $given): array
    {
        $index = 0;

        foreach ($given as $id => $units) {
            $item = $bundles->get($id) ?? $items->get($id);

            if (! $item instanceof OrderItem || $item->parent_item_id !== null) {
                throw OrderEditRefused::unknownLine('delivered_quantities', $index);
            }

            if ($units > $item->quantity) {
                throw OrderEditRefused::deliveredAboveOrdered($index, $item->quantity);
            }

            $index++;
        }

        $delivered = $bundles->map(fn (OrderItem $bundle): int => $given[$bundle->id] ?? $bundle->quantity)->all();

        foreach ($items as $item) {
            $parent = $item->parent_item_id === null ? null : $bundles->get($item->parent_item_id);
            $delivered[$item->id] = $parent instanceof OrderItem
                ? intdiv($item->quantity, max(1, $parent->quantity)) * $delivered[$parent->id]
                : $given[$item->id] ?? $item->quantity;
        }

        if (array_sum($delivered) === 0) {
            throw OrderEditRefused::nothingDelivered();
        }

        return $delivered;
    }

    /**
     * BR-O08 on what was delivered; what was collected must still fit (BR-C03).
     *
     * @param  Collection<int, OrderItem>  $items
     */
    private function recomputeTotals(Order $order, Collection $items): void
    {
        $this->totals->apply($order, $items, capDiscount: true);

        if (Money::compare($order->collected_amount, $order->netTotal()) > 0) {
            throw OrderEditRefused::belowCollected($order->collected_amount, $order->order_total);
        }
    }
}
