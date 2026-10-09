<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Data\CheckoutProblem;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Pricing\Services\PriceResolver;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * BR-PR06 steps 2–7 for every cart line, against the stock rows the caller locked: product active (BR-P04),
 * price resolved from the current tiers (BR-PR04), MOQ (BR-MOQ01), Available ≥ quantity × conversion factor
 * (BR-INV01, BR-U03), and the minimum order value on the items total (BR-MOV02). Every failure is collected, so
 * the customer sees all of them at once in one `CheckoutFailed`; nothing is written here.
 *
 * Expects the lines' `sku.product`, `sku.tiers` and `sku.sellingUnit` loaded (Customers' CartQuery).
 */
final readonly class CheckoutValidator
{
    public function __construct(
        private PriceResolver $prices,
        private CurrentTenantSettingsQuery $settings,
    ) {}

    /**
     * @param  Collection<int, CartItem>  $lines
     * @param  Collection<int, SkuInventory>  $stock  locked rows keyed by sku_id (StockLedger::lock)
     * @return list<CheckoutLine>
     */
    public function validate(Collection $lines, Collection $stock): array
    {
        $checked = [];
        $problems = [];
        $total = '0.00';

        foreach ($lines as $line) {
            $sku = $line->sku;
            $price = $this->prices->resolve($sku, $line->quantity);
            $total = Money::add($total, $price->lineTotal);

            $row = $stock->get($sku->id);
            $problem = $this->problem($line, $row instanceof SkuInventory ? $row->available : 0);

            if ($problem instanceof CheckoutProblem) {
                $problems[] = $problem;

                continue;
            }

            $checked[] = new CheckoutLine($sku, $line->quantity, $price);
        }

        $mov = $this->movShortfall($total);

        if ($problems !== [] || $mov !== null) {
            throw CheckoutFailed::with($problems, $mov);
        }

        return $checked;
    }

    private function problem(CartItem $line, int $available): ?CheckoutProblem
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
     * @return array{required: string, current: string}|null
     */
    private function movShortfall(string $total): ?array
    {
        $mov = $this->settings->minimumOrderValue();

        if (! $mov['enabled'] || $mov['amount'] === null || Money::compare($total, $mov['amount']) >= 0) {
            return null;
        }

        return ['required' => $mov['amount'], 'current' => $total];
    }
}
