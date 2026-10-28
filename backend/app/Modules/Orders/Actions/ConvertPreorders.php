<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Orders\Data\CartLineInput;
use App\Modules\Orders\Data\ConvertedPreorders;
use App\Modules\Orders\Data\OrderPlacementData;
use App\Modules\Orders\Enums\OrderItemChangeType;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PreorderState;
use App\Modules\Orders\Exceptions\PreordersNotConvertible;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderItemChange;
use App\Modules\Orders\Models\Scopes\Live;
use App\Modules\Orders\Services\OrderTotals;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * POST /api/dashboard/products/{id}/convert-preorders (BR-O14, D88): once a Coming Soon product is active and stocked,
 * the merchant turns its open pre-orders into normal orders, oldest `placed_at` first, as far as the stock goes. Each
 * pre-order is its own transaction:
 *
 * 1. lock the pre-order (still open?) and its customer (still active? else it is left untouched);
 * 2. lock the stock of the product's SKUs and take, per pre-order line, what is available up to what is left of it;
 * 3. place a normal order of those quantities through PlaceOrder (`converted_from_id`, the pre-order's delivery snapshot
 *    and note, priced at today's tiers, reserved; the user as the actor; no MOQ, minimum order value or credit check:
 *    the pre-order met those);
 * 4. keep the rest on the pre-order (`converted_quantity`, `converted` line changes, totals on what is left); a
 *    pre-order with nothing left is closed (`converted_at`);
 * 5. history «تحويل إلى طلب #N» on the pre-order, audit `order.preorder_converted`, and the customer's
 *    `preorder_converted` WhatsApp text after commit.
 *
 * A pre-order with no stock left for it is untouched. The answer is the merchant's summary.
 */
final readonly class ConvertPreorders
{
    public function __construct(
        private CartSkuQuery $skus,
        private StockLedger $ledger,
        private PlaceOrder $place,
        private OrderTotals $totals,
        private RecordStatusChange $history,
        private NotifyCustomerOfStatus $notify,
        private AuditLogger $audit,
    ) {}

    public function handle(Product $product, int $actorId): ConvertedPreorders
    {
        $product = Product::query()->findOrFail($product->id);

        if ($product->status !== ProductStatus::Active) {
            throw PreordersNotConvertible::productNotActive($product->status);
        }

        $skuIds = array_values(array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, Sku::query()->where('product_id', $product->id)->orderBy('id')->pluck('id')->all()));
        $orders = [];
        $partially = 0;
        $untouched = 0;

        foreach ($this->openPreorders($skuIds) as $preorderId) {
            $converted = DB::transaction(fn (): ?array => $this->convert($preorderId, $skuIds, $actorId));

            if ($converted === null) {
                $untouched++;

                continue;
            }

            $orders[] = $converted['order'];
            $partially += $converted['partial'] ? 1 : 0;
        }

        return new ConvertedPreorders($orders, $partially, $untouched);
    }

    /**
     * The open pre-orders with something left of these SKUs, oldest first (FIFO).
     *
     * @param  list<int>  $skuIds
     * @return list<int>
     */
    private function openPreorders(array $skuIds): array
    {
        return array_values(array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, Order::query()
            ->where('kind', OrderKind::Preorder)
            ->where('status', OrderStatus::Pending)
            ->whereNull('converted_at')
            ->whereHas('liveItems', fn (Builder $items): Builder => $items->whereIn('sku_id', $skuIds)->whereColumn('converted_quantity', '<', 'quantity'))
            ->orderBy('placed_at')
            ->orderBy('id')
            ->pluck('id')
            ->all()));
    }

    /**
     * @param  list<int>  $skuIds
     * @return array{order: array{number: int, preorder_number: int, quantity: int, order_total: string}, partial: bool}|null
     */
    private function convert(int $preorderId, array $skuIds, int $actorId): ?array
    {
        $preorder = Order::query()->lockForUpdate()->findOrFail($preorderId);
        $customer = Customer::query()->lockForUpdate()->findOrFail($preorder->customer_id);

        if ($preorder->preorderState() !== PreorderState::Open || $customer->status !== CustomerStatus::Active) {
            return null;
        }

        /** @var Collection<int, OrderItem> $items */
        $items = OrderItem::query()->where('order_id', $preorder->id)->tap(new Live)->whereIn('sku_id', $skuIds)->whereColumn('converted_quantity', '<', 'quantity')->orderBy('id')->get();
        $taken = $this->take($items);

        if ($taken === []) {
            return null;
        }

        $units = $this->skus->findMany(array_values(array_unique($items->map(fn (OrderItem $item): int => $item->sku_unit_id ?? 0)->all())));
        $lines = [];

        foreach ($taken as $itemId => $quantity) {
            $lines[] = new CartLineInput($units->get($items->firstWhere('id', $itemId)->sku_unit_id ?? 0) ?? throw new RuntimeException('Pre-order line without its SKU unit.'), $quantity);
        }

        $order = $this->place->handle(new OrderPlacementData($customer, null, $lines, ActorType::TenantUser, $actorId, $preorder->customer_note, skipMov: true, convertedFrom: $preorder));
        $this->keepRemainder($preorder, $items, $taken, $order, $actorId);

        $this->notify->preorderConverted($order);

        return [
            'order' => ['number' => $order->number, 'preorder_number' => $preorder->number, 'quantity' => array_sum($taken), 'order_total' => $order->order_total],
            'partial' => $items->contains(fn (OrderItem $item): bool => $item->remainingQuantity() > 0),
        ];
    }

    /**
     * What each line can have now: the stock of its SKU locked, up to what is left of the line, in whole units of the line;
     * lines of one SKU in several units share its stock, oldest line first (D91).
     *
     * @param  Collection<int, OrderItem>  $items
     * @return array<int, int> order item id => selling units
     */
    private function take(Collection $items): array
    {
        $stock = $this->ledger->lock(array_values($items->map(fn (OrderItem $item): int => $item->sku_id)->all()));
        $taken = [];
        $left = []; // sku_id => base units still free

        foreach ($items as $item) {
            $left[$item->sku_id] ??= max(0, $stock->get($item->sku_id)->available ?? 0);
            $quantity = min($item->remainingQuantity(), intdiv($left[$item->sku_id], $item->conversion_factor));

            if ($quantity > 0) {
                $taken[$item->id] = $quantity;
                $left[$item->sku_id] -= $quantity * $item->conversion_factor;
            }
        }

        return $taken;
    }

    /**
     * BR-O14: the converted units leave the pre-order line, which keeps the rest (charged at its indicative price); a
     * pre-order with nothing left anywhere is closed.
     *
     * @param  Collection<int, OrderItem>  $items
     * @param  array<int, int>  $taken
     */
    private function keepRemainder(Order $preorder, Collection $items, array $taken, Order $order, int $actorId): void
    {
        $newPrices = OrderItem::query()->where('order_id', $order->id)->pluck('unit_price', 'sku_unit_id');
        $changes = [];

        foreach ($items as $item) {
            $quantity = $taken[$item->id] ?? 0;

            if ($quantity === 0) {
                continue;
            }

            $before = $item->remainingQuantity();
            $converted = $item->converted_quantity + $quantity;
            $item->forceFill(['converted_quantity' => $converted, 'line_total' => Money::times($item->unit_price, $item->quantity - $converted)])->save();
            $price = $newPrices->get($item->sku_unit_id);

            OrderItemChange::query()->create([
                'order_id' => $preorder->id, 'order_item_id' => $item->id, 'change' => OrderItemChangeType::Converted,
                'old_quantity' => $before, 'new_quantity' => $item->remainingQuantity(),
                'unit_price_before' => $item->unit_price, 'unit_price_after' => is_string($price) ? $price : $item->unit_price, 'actor_id' => $actorId,
            ]);
            $changes[] = ['sku_id' => $item->sku_id, 'quantity' => $quantity, 'remaining' => $item->remainingQuantity()];
        }

        $live = OrderItem::query()->where('order_id', $preorder->id)->tap(new Live)->get();
        $this->totals->apply($preorder, $live);

        if ($live->every(fn (OrderItem $item): bool => $item->remainingQuantity() === 0)) {
            $preorder->forceFill(['converted_at' => now()]);
        }

        $preorder->save();

        $this->history->handle($preorder, $preorder->status, ActorType::TenantUser, $actorId, "تحويل إلى طلب #{$order->number}");
        $this->audit->log('order.preorder_converted', $preorder, null, [
            'order_number' => $order->number,
            'lines' => $changes,
            'order_total' => $preorder->order_total,
            ...($preorder->converted_at === null ? [] : ['converted' => true]),
        ]);
    }
}
