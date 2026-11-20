<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Customers\Models\Customer;

/**
 * BR-C05, D77: what a customer already owes the store or has on order, for the credit-limit step of checkout.
 * Collections implements it (CustomerExposureQuery, bound in CollectionsServiceProvider); without it there is no
 * figure to compare with, so NullCustomerExposure answers null and no limit is enforced.
 */
interface CustomerExposure
{
    /** "150.00", or null when exposure cannot be computed (no limit is enforced then). */
    public function of(Customer $customer): ?string;
}
