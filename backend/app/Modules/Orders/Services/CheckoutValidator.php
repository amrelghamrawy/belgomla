<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Contracts\CustomerExposure;
use App\Modules\Orders\Data\CartLineInput;
use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Data\CheckoutProblem;
use App\Modules\Orders\Data\CheckoutReport;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Pricing\Services\PriceResolver;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * BR-PR06 steps 2–7 for every line, against the stock rows given: product active (BR-P04), price resolved from the
 * current tiers (BR-PR04), MOQ (BR-MOQ01), Available ≥ quantity × conversion factor (BR-INV01, BR-U03), the minimum
 * order value on the items total (BR-MOV02), and the customer's credit limit against what they already owe or have on
 * order (BR-C05, D77). Every failure is collected, so the customer sees all of them at once; nothing is written here.
 * `check()` reports (the merchant's quote, on unlocked rows), `validate()` refuses (checkout, on locked rows).
 *
 * Expects the lines' `sku.product`, `sku.tiers` and `sku.sellingUnit` loaded (Catalog's CartSkuQuery::RELATIONS).
 */
final readonly class CheckoutValidator
{
    public function __construct(
        private PriceResolver $prices,
        private CurrentTenantSettingsQuery $settings,
        private CustomerExposure $exposure,
    ) {}

    /**
     * @param  list<CartLineInput>  $lines
     * @param  Collection<int, SkuInventory>  $stock  rows keyed by sku_id (StockLedger::lock at checkout)
     * @return list<CheckoutLine>
     *
     * @throws CheckoutFailed
     */
    public function validate(array $lines, Collection $stock, Customer $customer): array
    {
        return $this->check($lines, $stock, $customer)->assertPasses();
    }

    /**
     * @param  list<CartLineInput>  $lines
     * @param  Collection<int, SkuInventory>  $stock  rows keyed by sku_id
     */
    public function check(array $lines, Collection $stock, Customer $customer): CheckoutReport
    {
        $priced = [];
        $problems = [];
        $total = '0.00';

        foreach ($lines as $line) {
            $sku = $line->sku;
            $price = $this->prices->resolve($sku, $line->quantity);
            $total = Money::add($total, $price->lineTotal);
            $priced[] = new CheckoutLine($sku, $line->quantity, $price);

            $row = $stock->get($sku->id);
            $problem = $this->problem($line, $row instanceof SkuInventory ? $row->available : 0);

            if ($problem instanceof CheckoutProblem) {
                $problems[$sku->id] = $problem;
            }
        }

        $mov = $this->settings->minimumOrderValue();

        return new CheckoutReport($priced, $problems, $total, $mov['amount'], $this->movShortfall($mov['amount'], $total), $this->creditShortfall($customer, $total));
    }

    private function problem(CartLineInput $line, int $available): ?CheckoutProblem
    {
        $sku = $line->sku;

        if ($sku->product->status !== ProductStatus::Active) {
            return CheckoutProblem::productInactive($sku->id);
        }

        if ($line->quantity < $sku->moq) {
            return CheckoutProblem::belowMoq($sku->id, $sku->moq);
        }

        if ($line->quantity * $sku->conversion_factor > $available) {
            return CheckoutProblem::insufficientStock($sku->id, $line->quantity, intdiv(max(0, $available), $sku->conversion_factor));
        }

        return null;
    }

    /**
     * BR-C05: exposure + this order may reach the limit but not pass it; no limit, or no exposure figure, never refuses.
     *
     * @return array{limit: string, exposure: string, order_total: string}|null
     */
    private function creditShortfall(Customer $customer, string $total): ?array
    {
        if ($customer->credit_limit === null) {
            return null;
        }

        $exposure = $this->exposure->of($customer);

        if ($exposure === null || Money::compare(Money::add($exposure, $total), $customer->credit_limit) <= 0) {
            return null;
        }

        return ['limit' => $customer->credit_limit, 'exposure' => $exposure, 'order_total' => $total];
    }

    /**
     * BR-MOV02 against the store's amount (null = no minimum, CurrentTenantSettingsQuery::minimumOrderValue()).
     *
     * @return array{required: string, current: string}|null
     */
    private function movShortfall(?string $amount, string $total): ?array
    {
        if ($amount === null || Money::compare($total, $amount) >= 0) {
            return null;
        }

        return ['required' => $amount, 'current' => $total];
    }
}
