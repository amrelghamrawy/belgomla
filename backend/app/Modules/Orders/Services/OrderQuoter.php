<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Data\CartLineInput;
use App\Modules\Orders\Data\OrderQuote;
use App\Modules\Orders\Exceptions\CheckoutFailed;
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
     * @param  list<array{sku_id: int, quantity: int}>  $lines
     */
    public function quote(int $customerId, array $lines): OrderQuote
    {
        $customer = $this->customer($customerId);
        $inputs = $this->lines($lines);
        $stock = collect($inputs)->mapWithKeys(fn (CartLineInput $line): array => [$line->sku->id => $line->sku->inventory])->filter();

        $tiers = [];

        foreach ($inputs as $line) {
            $tiers[$line->sku->id] = $this->prices->tiers($line->sku);
        }

        return new OrderQuote($this->validator->check($inputs, $stock, $customer), $tiers);
    }
}
