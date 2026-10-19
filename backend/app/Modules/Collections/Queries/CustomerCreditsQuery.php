<?php

declare(strict_types=1);

namespace App\Modules\Collections\Queries;

use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Collection;

/** BR-C07: every credit of a customer, voided included, newest first (GET …/customers/{id}/credits), and one by id. */
final readonly class CustomerCreditsQuery
{
    /**
     * @return Collection<int, CustomerCredit>
     */
    public function of(Customer $customer): Collection
    {
        return CustomerCredit::query()->where('customer_id', $customer->id)->orderByDesc('id')->get();
    }

    /** A credit of the tenant by id; another tenant's is `404` (BR-T04). */
    public function find(int $id): CustomerCredit
    {
        return CustomerCredit::query()->findOrFail($id);
    }
}
