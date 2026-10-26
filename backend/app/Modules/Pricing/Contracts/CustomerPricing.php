<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\Data\PricingContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * BR-PR14 (V2 Task 11): the pricing context of a storefront visitor, for the modules that come before Customers (the
 * Catalog's product list, product page and price list). Implemented by Customers (PricingContextQuery, bound in
 * CustomersServiceProvider), so Catalog and Pricing never depend on Customers. A guest has none (null).
 */
interface CustomerPricing
{
    public function for(?Authenticatable $customer): ?PricingContext;
}
