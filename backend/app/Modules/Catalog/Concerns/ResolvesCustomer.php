<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The logged-in storefront customer, if any. The `customer` guard is registered by the Customers
 * module (Task 5); until it exists every visitor is a guest (same guard check as AuditLogger).
 */
trait ResolvesCustomer
{
    public function customer(): ?Authenticatable
    {
        if (! array_key_exists('customer', config()->array('auth.guards'))) {
            return null;
        }

        $customer = $this->user('customer');

        return $customer instanceof Authenticatable ? $customer : null;
    }
}
