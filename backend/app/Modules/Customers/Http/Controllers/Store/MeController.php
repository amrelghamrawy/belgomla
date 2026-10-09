<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store;

use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use Illuminate\Container\Attributes\CurrentUser;

final readonly class MeController
{
    /** GET /api/store/me — the logged-in customer's profile and status (BR-CU05) */
    public function show(#[CurrentUser('customer')] Customer $customer): CustomerResource
    {
        return new CustomerResource($customer);
    }
}
