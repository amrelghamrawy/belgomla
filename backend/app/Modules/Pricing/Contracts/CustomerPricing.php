<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\Data\PricingContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * BR-PR14 (V2 Task 11): the pricing context of a storefront visitor, for the modules that come before Customers (the
 * Catalog's product list, product page and price list). Implemented by Customers (PricingContextQuery, bound in
 * CustomersServiceProvider), so Catalog and Pricing never depend on Customers. It carries the live promotion of each of
 * `$skuIds` (BR-PR15, V2 Task 12); a guest has none (null) unless one of them is on a promotion.
 */
interface CustomerPricing
{
    /**
     * @param  list<int>  $skuIds  the SKUs about to be priced
     */
    public function for(?Authenticatable $customer, array $skuIds = [], ?CarbonImmutable $at = null): ?PricingContext;
}
