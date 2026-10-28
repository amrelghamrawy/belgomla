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
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Services\PriceResolver;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * BR-PR06 steps 2–7 for every line, against the stock rows given: product active (BR-P04), price resolved from the
 * current tiers at the customer's own prices (BR-PR04, BR-PR14: the caller hands in their PricingContext), all in the
 * line's SKU unit (V2 Task 13, D91), MOQ (BR-MOQ01), Available ≥ quantity × the unit's factor (BR-INV01, BR-U03) summed over
 * the order's lines of one SKU in order (piece and carton share the stock; a line that does not fit is refused and the
 * next ones are checked against what is left), the minimum
 * order value on the items total (BR-MOV02), and the customer's credit limit against what they already owe or have on
 * order (BR-C05, D77). Every failure is collected, so the customer sees all of them at once; nothing is written here.
 * `check()` reports (the merchant's quote, on unlocked rows), `validate()` refuses (checkout, on locked rows).
 *
 * Expects the lines' units loaded with Catalog's CartSkuQuery::RELATIONS.
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
    public function validate(array $lines, Collection $stock, Customer $customer, ?PricingContext $pricing): array
    {
        return $this->check($lines, $stock, $customer, $pricing)->assertPasses();
    }

    /**
     * @param  list<CartLineInput>  $lines
     * @param  Collection<int, SkuInventory>  $stock  rows keyed by sku_id
     * @param  string  $alreadyExposed  an order of these lines already counted in the customer's exposure (an edit's quote, BR-O12)
     */
    public function check(array $lines, Collection $stock, Customer $customer, ?PricingContext $pricing, string $alreadyExposed = '0.00'): CheckoutReport
    {
        $report = $this->checkLines($lines, $stock, $pricing);
        $mov = $this->settings->minimumOrderValue();

        return new CheckoutReport($report->lines, $report->problems, $report->total, $mov['amount'], $this->movShortfall($mov['amount'], $report->total), $this->creditShortfall($customer, $report->total, $alreadyExposed));
    }

    /**
     * The per-line checks only (BR-PR06 steps 2–5), without the order-level minimum and credit limit: EditOrderLines
     * checks the lines it adds or changes this way and the order's totals itself (BR-O12).
     *
     * @param  list<CartLineInput>  $lines
     * @param  Collection<int, SkuInventory>  $stock  rows keyed by sku_id
     */
    public function checkLines(array $lines, Collection $stock, ?PricingContext $pricing): CheckoutReport
    {
        $priced = [];
        $problems = [];
        $total = '0.00';
        $taken = []; // D91: base units the earlier lines take of each SKU (piece and carton share the stock)

        foreach ($lines as $line) {
            $unit = $line->unit;
            $price = $this->prices->resolve($unit, $line->quantity, $pricing);
            $total = Money::add($total, $price->lineTotal);
            $priced[] = new CheckoutLine($unit, $line->quantity, $price);

            $row = $stock->get($unit->sku_id);
            $available = ($row instanceof SkuInventory ? $row->available : 0) - ($taken[$unit->sku_id] ?? 0);
            $problem = $this->problem($line, $available);

            if ($problem instanceof CheckoutProblem) {
                $problems[$unit->id] = $problem;
            } else {
                $taken[$unit->sku_id] = ($taken[$unit->sku_id] ?? 0) + $line->quantityBase();
            }
        }

        return new CheckoutReport($priced, $problems, $total, null, null, null);
    }

    /**
     * BR-O14, D88: the checks of a pre-order, which holds no stock: the product is Coming Soon with pre-orders on, the MOQ,
     * the merchant's cap on what every open pre-order adds up to (`$open` = what is already pre-ordered per sku_id in base
     * units, read under the product lock; the cap is in the SKU's default unit, D91) and the credit limit. The minimum order
     * value does not apply.
     *
     * @param  list<CartLineInput>  $lines
     * @param  array<int, int>  $open  sku_id => open pre-ordered base units
     */
    public function checkPreorder(array $lines, Customer $customer, array $open, ?PricingContext $pricing): CheckoutReport
    {
        $priced = [];
        $problems = [];
        $total = '0.00';

        foreach ($lines as $line) {
            $unit = $line->unit;
            $price = $this->prices->resolve($unit, $line->quantity, $pricing);
            $total = Money::add($total, $price->lineTotal);
            $priced[] = new CheckoutLine($unit, $line->quantity, $price);
            $problem = $this->preorderProblem($line, $open[$unit->sku_id] ?? 0);

            if ($problem instanceof CheckoutProblem) {
                $problems[$unit->id] = $problem;
            } else {
                $open[$unit->sku_id] = ($open[$unit->sku_id] ?? 0) + $line->quantityBase();
            }
        }

        return new CheckoutReport($priced, $problems, $total, null, null, $this->creditShortfall($customer, $total));
    }

    private function preorderProblem(CartLineInput $line, int $open): ?CheckoutProblem
    {
        $unit = $line->unit;
        $product = $line->sku->product;

        if ($product->status !== ProductStatus::ComingSoon || ! $product->preorder_enabled || ! $line->sku->isActive() || ! $unit->isActive()) {
            return CheckoutProblem::preorderUnavailable($unit->sku_id, $unit->id);
        }

        if ($line->quantity < $unit->moq) {
            return CheckoutProblem::belowMoq($unit->sku_id, $unit->id, $unit->moq);
        }

        if ($product->preorder_limit === null) {
            return null;
        }

        $limit = $product->preorder_limit * ($line->sku->defaultUnit->conversion_factor ?? 1);

        return $open + $line->quantityBase() > $limit
            ? CheckoutProblem::preorderLimit($unit->sku_id, $unit->id, $line->quantity, intdiv(max(0, $limit - $open), $unit->conversion_factor))
            : null;
    }

    /** `$available`: base units left for this line after the order's earlier lines of the same SKU (D91). */
    private function problem(CartLineInput $line, int $available): ?CheckoutProblem
    {
        $unit = $line->unit;

        if ($line->sku->product->status !== ProductStatus::Active || ! $line->sku->isActive() || ! $unit->isActive()) {
            return CheckoutProblem::productInactive($unit->sku_id, $unit->id);
        }

        if ($line->quantity < $unit->moq) {
            return CheckoutProblem::belowMoq($unit->sku_id, $unit->id, $unit->moq);
        }

        if ($line->quantityBase() > $available) {
            return CheckoutProblem::insufficientStock($unit->sku_id, $unit->id, $line->quantity, intdiv(max(0, $available), $unit->conversion_factor));
        }

        return null;
    }

    /**
     * BR-C05: exposure + this order may reach the limit but not pass it; no limit, or no exposure figure, never refuses.
     * `$exclude` takes an amount already counted in the exposure out of it: the old total of an order being edited (BR-O12).
     *
     * @return array{limit: string, exposure: string, order_total: string}|null
     */
    public function creditShortfall(Customer $customer, string $total, string $exclude = '0.00'): ?array
    {
        if ($customer->credit_limit === null) {
            return null;
        }

        $exposure = $this->exposure->of($customer);
        $exposure = $exposure === null ? null : Money::subtract($exposure, $exclude);

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
    public function movShortfall(?string $amount, string $total): ?array
    {
        if ($amount === null || Money::compare($total, $amount) >= 0) {
            return null;
        }

        return ['required' => $amount, 'current' => $total];
    }
}
