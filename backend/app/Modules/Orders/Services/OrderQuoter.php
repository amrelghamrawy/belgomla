<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Queries\PricingContextQuery;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Data\CartLineInput;
use App\Modules\Orders\Data\OrderQuote;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Scopes\Live;
use App\Modules\Pricing\Services\PriceResolver;

/**
 * BR-O11: the merchant's order form. Finds the customer and the SKUs of the lines the merchant typed (PlaceOrderForCustomer
 * places them), and quotes them: the same checks as checkout (CheckoutValidator) on the stock as it is now, without
 * locking or writing anything, so the form shows the prices, tiers, stock and problems live, at the customer's own
 * prices (BR-PR14): their tiers are the ones that still lower their price. A bundle line (BR-P15, D93) is checked on
 * its components' stock.
 */
final readonly class OrderQuoter
{
    public function __construct(
        private CartSkuQuery $skus,
        private CheckoutValidator $validator,
        private PriceResolver $prices,
        private PricingContextQuery $pricing,
    ) {}

    /** A customer of this store (tenant scope), whatever their status: PlaceOrder decides who may order. */
    public function customer(int $customerId): Customer
    {
        return Customer::query()->find($customerId) ?? throw CheckoutFailed::customerUnavailable();
    }

    /**
     * @param  list<array{sku_unit_id: int, quantity: int}>  $lines
     * @return list<CartLineInput>
     */
    public function lines(array $lines): array
    {
        $units = $this->skus->findMany(array_column($lines, 'sku_unit_id'));
        $inputs = [];

        foreach ($lines as $index => $line) {
            $inputs[] = new CartLineInput($units->get($line['sku_unit_id']) ?? throw CheckoutFailed::skuUnavailable($index), $line['quantity']);
        }

        return $inputs;
    }

    /**
     * `$orderNumber` quotes an edit of that order of the customer (BR-O12): the units it holds of a SKU count as
     * available, and its net total is already part of the customer's exposure.
     *
     * @param  list<array{sku_unit_id: int, quantity: int}>  $lines
     */
    public function quote(int $customerId, array $lines, ?int $orderNumber = null): OrderQuote
    {
        $customer = $this->customer($customerId);
        $inputs = $this->lines($lines);
        $order = $orderNumber === null ? null : $this->order($customer, $orderNumber);
        $held = $order instanceof Order ? $this->held($order) : [];
        $pricing = $this->pricing->for($customer, array_values(array_unique(array_merge([], ...array_map(fn (CartLineInput $line): array => $line->pricedSkuIds(), $inputs)))));

        $tiers = [];

        foreach ($inputs as $line) {
            $tiers[$line->unit->id] = $this->prices->listed($line->unit, $pricing)->tiers;
        }

        $skus = collect($inputs)->flatMap(fn (CartLineInput $line): array => $line->stockSkus())->unique('id');

        foreach ($skus as $sku) {
            $this->countHeld($sku, $held[$sku->id] ?? 0);
        }

        $stock = $skus->mapWithKeys(fn (Sku $sku): array => [$sku->id => $sku->inventory])->filter();

        return new OrderQuote($this->validator->check($inputs, $stock, $customer, $pricing, $order?->netTotal() ?? '0.00'), $tiers);
    }

    private function order(Customer $customer, int $number): Order
    {
        return Order::query()->where('customer_id', $customer->id)->where('kind', OrderKind::Order)->where('number', $number)->first() ?? throw CheckoutFailed::orderUnavailable(); // a pre-order is not edited (D88)
    }

    /**
     * @return array<int, int> sku_id => base units the order's live lines hold (summed over its units, D91)
     */
    private function held(Order $order): array
    {
        if (! $order->status->allowsLineEdit()) {
            return [];
        }

        $held = [];

        foreach (OrderItem::query()->where('order_id', $order->id)->tap(new Live)->get(['sku_id', 'quantity_base']) as $item) {
            $held[$item->sku_id] = ($held[$item->sku_id] ?? 0) + $item->quantity_base;
        }

        return $held;
    }

    /** A copy of the SKU's stock row where what the order holds is available again; nothing is saved. */
    private function countHeld(Sku $sku, int $held): void
    {
        if ($held === 0 || ! $sku->inventory instanceof SkuInventory) {
            return;
        }

        $copy = clone $sku->inventory;
        $copy->reserved -= $held;
        $sku->setRelation('inventory', $copy);
    }
}
