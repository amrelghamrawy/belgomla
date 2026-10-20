<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Data\DiscountData;
use App\Modules\Orders\Data\LineDiscountData;
use App\Modules\Orders\Exceptions\DiscountRefused;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Scopes\Live;
use App\Modules\Pricing\Services\DiscountCalculator;
use App\Modules\Pricing\Services\PercentPricing;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Queries\CurrentPermissionsQuery;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/dashboard/orders/{number}/discount (BR-PR08, BR-O08, D85): the merchant sets the manual discounts of an
 * order, per line (EGP or % off the unit's list price) and on the whole order (EGP or % off the items total, with a
 * reason). The data is the whole state: a line left out, or an order discount without a type, has none, so removing
 * a discount restores the list price. Owns its transaction (as a step of PlaceOrderForCustomer it joins the
 * caller's): the order row is locked and re-read; only a pending or confirmed order (`409 order_locked`); a unit
 * never below 0.01 and the order never below 0.01 (`422 discount_too_large`); the total never below what was
 * collected (`409 below_collected`). A sales user gives line discounts only, up to the store's cap
 * (`403 discount_over_limit`). Then the lines, `items_total`, `discount_amount` and `order_total` are rewritten, the
 * collection status re-derived, the history gets the note «خصم», `order.discount_changed` is audited with the old
 * and new values of what changed, and the customer is told when the store notifies `discount` (BR-N03). When
 * nothing changes nothing is written.
 */
final readonly class ApplyOrderDiscount
{
    public function __construct(
        private DiscountCalculator $calculator,
        private PercentPricing $percents,
        private CurrentPermissionsQuery $permissions,
        private CurrentTenantSettingsQuery $settings,
        private ApplyCollectedAmount $collected,
        private RecordStatusChange $history,
        private NotifyCustomerOfStatus $notify,
        private AuditLogger $audit,
    ) {}

    public function handle(Order $order, DiscountData $data, int $actorId, bool $notifyCustomer = true): Order
    {
        return DB::transaction(function () use ($order, $data, $actorId, $notifyCustomer): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->status->allowsPriceChange()) {
                throw DiscountRefused::locked($order->status);
            }

            /** @var array<int, OrderItem> $items */
            $items = OrderItem::query()->where('order_id', $order->id)->tap(new Live)->orderBy('id')->get()->keyBy('id')->all(); // a removed line takes no discount (BR-O12)
            $discounts = $this->lineDiscounts($items, $data->items);
            $oldItems = array_map($this->itemSnapshot(...), $items);
            $oldOrder = $this->orderSnapshot($order);

            $this->applyToLines($items, $discounts);
            $this->applyToOrder($order, $items, $data);

            $changedItems = array_filter(array_map($this->itemSnapshot(...), $items), fn (array $new, int $id): bool => $new !== $oldItems[$id], ARRAY_FILTER_USE_BOTH);
            $newOrder = $this->orderSnapshot($order);

            if ($changedItems === [] && $newOrder === $oldOrder) {
                return $order;
            }

            $this->assertWithinLimit($items, array_keys($changedItems), $order->discount_type !== null && $newOrder['discount'] !== $oldOrder['discount']);
            $this->assertCovered($order);

            foreach (array_keys($changedItems) as $id) {
                $items[$id]->save();
            }

            $order->save();
            $this->collected->handle($order, $order->collected_amount);
            $this->history->handle($order, $order->status, ActorType::TenantUser, $actorId, 'خصم');
            $this->audit->log('order.discount_changed', $order,
                ['items' => array_values(array_intersect_key($oldItems, $changedItems)), ...$oldOrder],
                ['items' => array_values($changedItems), ...$newOrder],
            );

            if ($notifyCustomer) {
                $this->notify->discounted($order);
            }

            return $order;
        });
    }

    /**
     * Every requested line must be a line of this order (else 422 on its field).
     *
     * @param  array<int, OrderItem>  $items
     * @param  list<LineDiscountData>  $lines
     * @return array<int, LineDiscountData> order item id => its discount
     */
    private function lineDiscounts(array $items, array $lines): array
    {
        $byId = [];

        foreach ($lines as $index => $line) {
            if (! isset($items[$line->orderItemId])) {
                throw DiscountRefused::unknownLine($index);
            }

            $byId[$line->orderItemId] = $line;
        }

        return $byId;
    }

    /**
     * @param  array<int, OrderItem>  $items
     * @param  array<int, LineDiscountData>  $discounts
     */
    private function applyToLines(array $items, array $discounts): void
    {
        foreach ($items as $item) {
            $type = ($discounts[$item->id] ?? null)?->type;
            $value = ($discounts[$item->id] ?? null)?->value;
            $given = $type !== null && $value !== null;

            $unit = $given ? $this->calculator->unitAfter($item->list_unit_price, $type, $value) : $item->list_unit_price;

            $item->forceFill([
                'manual_discount_type' => $given ? $type : null,
                'manual_discount_value' => $given ? Money::normalize($value) : null,
                'unit_price' => $unit,
                'line_total' => Money::times($unit, $item->quantity),
            ]);
        }
    }

    /**
     * BR-O08: order_total = items_total − discount_amount.
     *
     * @param  array<int, OrderItem>  $items
     */
    private function applyToOrder(Order $order, array $items, DiscountData $data): void
    {
        $itemsTotal = array_reduce($items, fn (string $sum, OrderItem $item): string => Money::add($sum, $item->line_total), '0.00');
        $type = $data->type;
        $value = $data->value;
        $given = $type instanceof \App\Modules\Pricing\Enums\DiscountType && $value !== null;
        $amount = $given ? $this->calculator->orderAmount($itemsTotal, $type, $value) : '0.00';

        $order->forceFill([
            'items_total' => $itemsTotal,
            'discount_type' => $given ? $type : null,
            'discount_value' => $given ? Money::normalize($value) : null,
            'discount_amount' => $amount,
            'discount_reason' => $given ? $data->reason : null,
            'order_total' => Money::subtract($itemsTotal, $amount),
        ]);
    }

    /**
     * D85: owners and managers give any discount; a sales user only line discounts, each at most the store's cap in %
     * off the list price (no cap = no discount). Only what this request changes is checked, so a sales user can
     * still remove a discount or leave a manager's discount as it is.
     *
     * @param  array<int, OrderItem>  $items
     * @param  list<int>  $changed  ids of the lines whose discount changed
     */
    private function assertWithinLimit(array $items, array $changed, bool $orderDiscountGiven): void
    {
        if ($this->permissions->role() !== TenantUserRole::Sales) {
            return;
        }

        $cap = $this->settings->maxDiscountPercentSales();
        $given = array_filter($changed, fn (int $id): bool => $items[$id]->manual_discount_type !== null);

        if ($orderDiscountGiven || ($given !== [] && $cap === null)) {
            throw DiscountRefused::overLimit($cap);
        }

        foreach ($given as $id) {
            $percent = $this->percents->percentOff($items[$id]->list_unit_price, $items[$id]->unit_price) ?? '0.00';

            if (Money::compare($percent, $cap) > 0) {
                throw DiscountRefused::overLimit($cap);
            }
        }
    }

    /** BR-C03: what was collected must still fit in the net total. */
    private function assertCovered(Order $order): void
    {
        if (Money::compare($order->collected_amount, $order->netTotal()) > 0) {
            throw DiscountRefused::belowCollected($order->collected_amount, $order->order_total);
        }
    }

    /**
     * @return array{order_item_id: int, list_unit_price: string, type: string|null, value: string|null, unit_price: string, line_total: string}
     */
    private function itemSnapshot(OrderItem $item): array
    {
        return [
            'order_item_id' => $item->id,
            'list_unit_price' => $item->list_unit_price,
            'type' => $item->manual_discount_type?->value,
            'value' => $item->manual_discount_value,
            'unit_price' => $item->unit_price,
            'line_total' => $item->line_total,
        ];
    }

    /**
     * @return array{items_total: string, discount: array{type: string|null, value: string|null, amount: string, reason: string|null}, order_total: string}
     */
    private function orderSnapshot(Order $order): array
    {
        return [
            'items_total' => $order->items_total,
            'discount' => [
                'type' => $order->discount_type?->value,
                'value' => $order->discount_value,
                'amount' => $order->discount_amount,
                'reason' => $order->discount_reason,
            ],
            'order_total' => $order->order_total,
        ];
    }
}
