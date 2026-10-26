<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Queries\PricingContextQuery;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Data\CartLineInput;
use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Data\EditedOrder;
use App\Modules\Orders\Data\OrderEditData;
use App\Modules\Orders\Data\OrderEditLineData;
use App\Modules\Orders\Enums\OrderItemChangeType;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Exceptions\OrderEditRefused;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderItemChange;
use App\Modules\Orders\Models\Scopes\Live;
use App\Modules\Orders\Services\CheckoutValidator;
use App\Modules\Orders\Services\OrderSnapshots;
use App\Modules\Orders\Services\OrderTotals;
use App\Modules\Pricing\Services\DiscountCalculator;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/dashboard/orders/{number}/lines (BR-O12, D87): the merchant changes the lines of a pending or confirmed
 * order (add a SKU, remove a line, change a quantity) instead of cancelling it. Owns its transaction:
 *
 * 1. lock and re-read the order; only pending or confirmed (`409 order_locked`);
 * 2. diff the wanted lines against the live ones (an unknown line id or a changed SKU is `422 validation_failed`);
 * 3. lock the stock of every SKU involved in sku_id order;
 * 4. check the added and changed lines like checkout (CheckoutValidator, BR-PR06: product active, MOQ, stock), with
 *    this order's own reservation of the SKU counted as available; any failure is `422 checkout_failed` like checkout;
 * 5. release what was removed or decreased, reserve what was added or increased (BR-INV03, BR-INV04);
 * 6. price the added and changed lines at the current tiers and the customer's own prices (BR-PR04, BR-PR14); unchanged lines keep their snapshot (BR-O05);
 *    a line keeps its manual discount (D85) and a % order discount is taken off the new items total;
 * 7. totals (BR-O08); never below what was collected (`409 below_collected`); a higher total must fit the customer's
 *    credit limit (BR-C05, `422 checkout_failed` with `details.credit`); going under the minimum order value is allowed
 *    and reported (`belowMov`, BR-MOV02);
 * 8. `order_item_changes`, the history note «تعديل الطلب», the `order.lines_changed` audit, and the `edited` WhatsApp
 *    text after commit when the store sends it (D87).
 *
 * Sending the lines the order already has writes nothing.
 */
final readonly class EditOrderLines
{
    public const string NOTE = 'تعديل الطلب';

    public function __construct(
        private CartSkuQuery $skus,
        private StockLedger $ledger,
        private CheckoutValidator $validator,
        private PricingContextQuery $pricing,
        private DiscountCalculator $calculator,
        private OrderSnapshots $snapshots,
        private OrderTotals $totals,
        private CurrentTenantSettingsQuery $settings,
        private ApplyCollectedAmount $collected,
        private RecordStatusChange $history,
        private NotifyCustomerOfStatus $notify,
        private AuditLogger $audit,
    ) {}

    public function handle(Order $order, OrderEditData $data, int $actorId): EditedOrder
    {
        return DB::transaction(function () use ($order, $data, $actorId): EditedOrder {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->isPreorder()) {
                throw OrderEditRefused::preorder();
            }

            if (! $order->status->allowsLineEdit()) {
                throw OrderEditRefused::locked($order->status);
            }

            /** @var array<int, OrderItem> $live */
            $live = OrderItem::query()->with('sku')->where('order_id', $order->id)->tap(new Live)->orderBy('id')->get()->keyBy('id')->all();
            [$changed, $added, $removed] = $this->diff($live, $data->lines);

            if ($changed === [] && $added === [] && $removed === []) {
                return new EditedOrder($order);
            }

            $before = $this->totalsOf($order);
            $customer = Customer::query()->findOrFail($order->customer_id);
            $priced = $this->check($live, $changed, $added, $removed, $customer);
            $changes = [
                ...$this->remove($removed),
                ...$this->change($live, $changed, $priced),
                ...$this->add($order, $added, $priced),
            ];

            $this->totals->apply($order, OrderItem::query()->where('order_id', $order->id)->tap(new Live)->get());
            $this->assertAffordable($order, $customer, $before);
            $order->save();
            $this->collected->handle($order, $order->collected_amount);

            $this->record($order, $changes, $before, $data->note, $actorId);
            $this->notify->edited($order);

            $mov = $this->settings->minimumOrderValue();

            return new EditedOrder($order, $this->validator->movShortfall($mov['amount'], $order->order_total));
        });
    }

    /**
     * Step 2: which live lines change quantity (id => new quantity), which lines are new (request index => line) and
     * which live lines are left out.
     *
     * @param  array<int, OrderItem>  $live
     * @param  list<OrderEditLineData>  $lines
     * @return array{0: array<int, int>, 1: array<int, OrderEditLineData>, 2: list<OrderItem>}
     */
    private function diff(array $live, array $lines): array
    {
        $changed = [];
        $added = [];
        $kept = [];

        foreach ($lines as $index => $line) {
            if ($line->orderItemId === null) {
                $added[$index] = $line;

                continue;
            }

            $item = $live[$line->orderItemId] ?? throw OrderEditRefused::unknownLine('lines', $index);

            if ($item->sku_id !== $line->skuId) {
                throw OrderEditRefused::skuChanged($index);
            }

            $kept[$item->id] = true;

            if ($item->quantity !== $line->quantity) {
                $changed[$item->id] = $line->quantity;
            }
        }

        return [$changed, $added, array_values(array_filter($live, fn (OrderItem $item): bool => ! isset($kept[$item->id])))];
    }

    /**
     * Steps 3–4: lock every SKU involved, then check the added and changed lines on the locked stock plus what this
     * order holds of them. Nothing is written when a line fails.
     *
     * @param  array<int, OrderItem>  $live
     * @param  array<int, int>  $changed
     * @param  array<int, OrderEditLineData>  $added
     * @param  list<OrderItem>  $removed
     * @return array<int, CheckoutLine> sku_id => the line priced at its new quantity
     */
    private function check(array $live, array $changed, array $added, array $removed, Customer $customer): array
    {
        $skus = $this->skus->findMany([...array_map(fn (int $id): int => $live[$id]->sku_id, array_keys($changed)), ...array_values(array_map(fn (OrderEditLineData $line): int => $line->skuId, $added))]);
        $inputs = [];
        $own = [];

        foreach ($changed as $id => $quantity) {
            $item = $live[$id];
            $inputs[] = new CartLineInput($skus->get($item->sku_id) ?? $item->sku->load(CartSkuQuery::RELATIONS), $quantity);
            $own[$item->sku_id] = $item->quantity_base;
        }

        foreach ($added as $index => $line) {
            $inputs[] = new CartLineInput($skus->get($line->skuId) ?? throw CheckoutFailed::skuUnavailable($index), $line->quantity);
        }

        $stock = $this->ledger->lock([
            ...array_map(fn (CartLineInput $input): int => $input->sku->id, $inputs),
            ...array_map(fn (OrderItem $item): int => $item->sku_id, $removed),
        ]);

        $pricing = $this->pricing->for($customer, array_map(fn (CartLineInput $input): int => $input->sku->id, $inputs));
        $report = $this->validator->checkLines($inputs, $this->withOwnReservation($stock, $own), $pricing);

        if ($report->problems !== []) {
            throw CheckoutFailed::with(array_values($report->problems));
        }

        $priced = [];

        foreach ($report->lines as $line) {
            $priced[$line->sku->id] = $line;
        }

        return $priced;
    }

    /**
     * Copies of the locked rows where this order's own reservation of a SKU counts as available again (BR-O12), only
     * for the check: the ledger re-reads the rows it writes.
     *
     * @param  Collection<int, SkuInventory>  $stock
     * @param  array<int, int>  $own  sku_id => base units this order holds
     * @return BaseCollection<int, SkuInventory>
     */
    private function withOwnReservation(Collection $stock, array $own): BaseCollection
    {
        return $stock->toBase()->map(function (SkuInventory $row) use ($own): SkuInventory {
            $copy = clone $row;
            $copy->reserved -= $own[$row->sku_id] ?? 0;

            return $copy;
        });
    }

    /**
     * Step 5 for the lines left out: their reservation goes back (BR-INV04), the line stays for history.
     *
     * @param  list<OrderItem>  $removed
     * @return list<array<string, mixed>>
     */
    private function remove(array $removed): array
    {
        $changes = [];

        foreach ($removed as $item) {
            $this->ledger->release($item->sku, $item->quantity_base);
            $item->forceFill(['removed_at' => now()])->save();
            $changes[] = ['order_item_id' => $item->id, 'change' => OrderItemChangeType::Removed, 'old_quantity' => $item->quantity, 'unit_price_before' => $item->unit_price];
        }

        return $changes;
    }

    /**
     * Steps 5–6 for the lines whose quantity changed: the reservation follows the new quantity, the price the current
     * tiers, and the manual discount stays on the new list price (D85).
     *
     * @param  array<int, OrderItem>  $live
     * @param  array<int, int>  $changed
     * @param  array<int, CheckoutLine>  $priced
     * @return list<array<string, mixed>>
     */
    private function change(array $live, array $changed, array $priced): array
    {
        $changes = [];

        foreach ($changed as $id => $quantity) {
            $item = $live[$id];
            $line = $priced[$item->sku_id];
            $difference = $line->quantityBase() - $item->quantity_base;

            if ($difference > 0) {
                $this->ledger->reserve($line->sku, $difference);
            } elseif ($difference < 0) {
                $this->ledger->release($line->sku, -$difference);
            }

            $old = ['quantity' => $item->quantity, 'unit_price' => $item->unit_price];
            $list = $line->price->unitPrice;
            $unit = $item->manual_discount_type !== null && $item->manual_discount_value !== null
                ? $this->calculator->unitAfter($list, $item->manual_discount_type, $item->manual_discount_value)
                : $list;

            $item->forceFill([
                'unit_name' => $line->sku->sellingUnit->name,
                'conversion_factor' => $line->sku->conversion_factor,
                'quantity' => $quantity,
                'quantity_base' => $line->quantityBase(),
                'cover_price' => $line->price->coverPrice,
                'list_unit_price' => $list,
                'unit_price' => $unit,
                'discount_percent' => $line->price->discountPercent,
                'tier_min_quantity' => $line->price->tierMinQuantity,
                'price_source' => $line->price->source,
                'promotion_id' => $line->price->promotion?->promotionId,
                'line_total' => Money::times($unit, $quantity),
            ])->save();

            $changes[] = ['order_item_id' => $item->id, 'change' => OrderItemChangeType::Quantity, 'old_quantity' => $old['quantity'], 'new_quantity' => $quantity, 'unit_price_before' => $old['unit_price'], 'unit_price_after' => $unit];
        }

        return $changes;
    }

    /**
     * Steps 5–6 for the new lines: snapshotted like at checkout (BR-PR07) and reserved (BR-INV03).
     *
     * @param  array<int, OrderEditLineData>  $added
     * @param  array<int, CheckoutLine>  $priced
     * @return list<array<string, mixed>>
     */
    private function add(Order $order, array $added, array $priced): array
    {
        $changes = [];

        foreach ($added as $line) {
            $checked = $priced[$line->skuId];
            $item = $order->items()->create($this->snapshots->item($checked));
            $this->ledger->reserve($checked->sku, $checked->quantityBase());
            $changes[] = ['order_item_id' => $item->id, 'change' => OrderItemChangeType::Added, 'new_quantity' => $line->quantity, 'unit_price_after' => $item->unit_price];
        }

        return $changes;
    }

    /**
     * Step 7: what was collected must still fit (BR-C03), and a higher total must stay within the credit limit (BR-C05;
     * the old total is already part of the exposure).
     *
     * @param  array{items_total: string, discount_amount: string, order_total: string}  $before
     */
    private function assertAffordable(Order $order, Customer $customer, array $before): void
    {
        if (Money::compare($order->collected_amount, $order->netTotal()) > 0) {
            throw OrderEditRefused::belowCollected($order->collected_amount, $order->order_total);
        }

        if (Money::compare($order->order_total, $before['order_total']) <= 0) {
            return;
        }

        $credit = $this->validator->creditShortfall($customer, $order->netTotal(), Money::subtract($before['order_total'], $order->returned_total));

        if ($credit !== null) {
            throw CheckoutFailed::with([], null, $credit);
        }
    }

    /**
     * Step 8.
     *
     * @param  list<array<string, mixed>>  $changes
     * @param  array{items_total: string, discount_amount: string, order_total: string}  $before
     */
    private function record(Order $order, array $changes, array $before, ?string $note, int $actorId): void
    {
        foreach ($changes as $change) {
            OrderItemChange::query()->create(['order_id' => $order->id, 'actor_id' => $actorId, ...$change]);
        }

        $this->history->handle($order, $order->status, ActorType::TenantUser, $actorId, $note === null ? self::NOTE : self::NOTE.': '.$note);
        $this->audit->log('order.lines_changed', $order, $before, [
            ...$this->totalsOf($order),
            'changes' => array_map(fn (array $change): array => [...$change, 'change' => $change['change'] instanceof OrderItemChangeType ? $change['change']->value : null], $changes),
            ...($note === null ? [] : ['note' => $note]),
        ]);
    }

    /**
     * @return array{items_total: string, discount_amount: string, order_total: string}
     */
    private function totalsOf(Order $order): array
    {
        return ['items_total' => $order->items_total, 'discount_amount' => $order->discount_amount, 'order_total' => $order->order_total];
    }
}
