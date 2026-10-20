<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Models\Customer;
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
 * locking or writing anything, so the form shows the prices, tiers, stock and problems live.
 */
final readonly class OrderQuoter
{
    public function __construct(
        private CartSkuQuery $skus,
        private CheckoutValidator $validator,
        private PriceResolver $prices,
    ) {}

    /** A customer of this store (tenant scope), whatever their status: PlaceOrder decides who may order. */
    public function customer(int $customerId): Customer
    {
        return Customer::query()->find($customerId) ?? throw CheckoutFailed::customerUnavailable();
    }

    /**
     * @param  list<array{sku_id: int, quantity: int}>  $lines
     * @return list<CartLineInput>
     */
    public function lines(array $lines): array
    {
        $skus = $this->skus->findMany(array_column($lines, 'sku_id'));
        $inputs = [];

        foreach ($lines as $index => $line) {
            $inputs[] = new CartLineInput($skus->get($line['sku_id']) ?? throw CheckoutFailed::skuUnavailable($index), $line['quantity']);
        }

        return $inputs;
    }

    /**
     * `$orderNumber` quotes an edit of that order of the customer (BR-O12): the units it holds of a SKU count as
     * available, and its net total is already part of the customer's exposure.
     *
     * @param  list<array{sku_id: int, quantity: int}>  $lines
     */
    public function quote(int $customerId, array $lines, ?int $orderNumber = null): OrderQuote
    {
        $customer = $this->customer($customerId);
        $inputs = $this->lines($lines);
        $order = $orderNumber === null ? null : $this->order($customer, $orderNumber);
        $held = $order instanceof Order ? $this->held($order) : [];

        $tiers = [];

        foreach ($inputs as $line) {
            $tiers[$line->sku->id] = $this->prices->tiers($line->sku);
            $this->countHeld($line->sku, $held[$line->sku->id] ?? 0);
        }

        $stock = collect($inputs)->mapWithKeys(fn (CartLineInput $line): array => [$line->sku->id => $line->sku->inventory])->filter();

        return new OrderQuote($this->validator->check($inputs, $stock, $customer, $order?->netTotal() ?? '0.00'), $tiers);
    }

    private function order(Customer $customer, int $number): Order
    {
        return Order::query()->where('customer_id', $customer->id)->where('kind', OrderKind::Order)->where('number', $number)->first() ?? throw CheckoutFailed::orderUnavailable(); // a pre-order is not edited (D88)
    }

    /**
     * @return array<int, int> sku_id => base units the order's live lines hold
     */
    private function held(Order $order): array
    {
        if (! $order->status->allowsLineEdit()) {
            return [];
        }

        $held = [];

        foreach (OrderItem::query()->where('order_id', $order->id)->tap(new Live)->get(['sku_id', 'quantity_base']) as $item) {
            $held[$item->sku_id] = $item->quantity_base;
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
