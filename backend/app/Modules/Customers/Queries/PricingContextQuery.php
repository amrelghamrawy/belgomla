<?php

declare(strict_types=1);

namespace App\Modules\Customers\Queries;

use App\Modules\Customers\Enums\CustomerGroupStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Models\CustomerSkuPrice;
use App\Modules\Pricing\Contracts\CustomerPricing;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Services\ActivePromotions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * BR-PR12..PR14 (V2 Task 11, D89): the customer's PricingContext in one query: the prices negotiated with them, plus
 * (a union row without a SKU) the % of their group when it is active. An archived group no longer applies. A guest has no
 * context (null). Callers build it once per request and hand it to the PriceResolver: the storefront through the
 * CustomerPricing contract, the cart, checkout, the merchant's quote and order, order edits and pre-order conversion
 * directly.
 *
 * BR-PR15 (V2 Task 12, D90): the context also carries the live promotion of each SKU the caller is about to price
 * (`$skuIds`, one more query through Pricing's ActivePromotions, at `$at` or now), for guests too: a guest gets a context
 * only when one of those SKUs is on a promotion.
 */
final readonly class PricingContextQuery implements CustomerPricing
{
    public function __construct(private ActivePromotions $promotions) {}

    /**
     * @param  list<int>  $skuIds
     */
    public function for(?Authenticatable $customer, array $skuIds = [], ?CarbonImmutable $at = null): ?PricingContext
    {
        $promotions = $this->promotions->forSkus($skuIds, $at);

        if (! $customer instanceof Customer) {
            return $promotions === [] ? null : new PricingContext(promotions: $promotions);
        }

        $rows = CustomerSkuPrice::query()
            ->where('customer_id', $customer->id)
            ->select(['sku_id', 'unit_price as amount']);

        if ($customer->customer_group_id !== null) {
            $rows->union(
                CustomerGroup::query()
                    ->whereKey($customer->customer_group_id)
                    ->where('status', CustomerGroupStatus::Active)
                    ->selectRaw('null as sku_id, discount_percent as amount'),
            );
        }

        $group = null;
        $prices = [];

        foreach ($rows->toBase()->get() as $row) {
            /** @var object{sku_id: int|null, amount: string} $row */
            if ($row->sku_id === null) {
                $group = $row->amount;
            } else {
                $prices[(int) $row->sku_id] = $row->amount;
            }
        }

        return new PricingContext($group, $prices, $promotions);
    }
}
