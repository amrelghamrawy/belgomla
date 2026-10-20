<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Models\Product;

/**
 * BR-O14, D88: how much of a Coming Soon product customers have pre-ordered and not yet received as normal orders, for
 * the storefront's remaining quantity under the product's cap and the merchant's purchase planning. Implemented by
 * Orders (PreorderDemandQuery, bound in OrdersServiceProvider), so Catalog never depends on Orders.
 */
interface PreorderDemand
{
    /**
     * The open pre-ordered quantity (selling units), how many customers and how many pre-orders hold it.
     *
     * @return array{quantity: int, customers: int, preorders: int}
     */
    public function of(Product $product): array;
}
