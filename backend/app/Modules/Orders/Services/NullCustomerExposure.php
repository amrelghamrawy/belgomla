<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Contracts\CustomerExposure;

/** CustomerExposure without the Collections module: nothing to measure, so no credit limit is enforced. */
final readonly class NullCustomerExposure implements CustomerExposure
{
    public function of(Customer $customer): ?string
    {
        return null;
    }
}
