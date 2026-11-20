<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Controllers\Dashboard;

use App\Modules\Collections\Http\Resources\CustomerCreditResource;
use App\Modules\Collections\Queries\CustomerCreditsQuery;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class CustomerCreditController
{
    /** GET /api/dashboard/customers/{id}/credits — BR-C07: every credit of the customer with what is left, newest first */
    public function index(Customer $customer, CustomerCreditsQuery $credits): AnonymousResourceCollection
    {
        return CustomerCreditResource::collection($credits->of($customer));
    }
}
