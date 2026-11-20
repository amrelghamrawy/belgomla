<?php

declare(strict_types=1);

namespace App\Modules\Customers\Queries;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Dashboard customer list (08-api-contract.md §8): `q` matches the name or the phone digits, `status` filters, `group`
 * keeps one customer group's members (V2 Task 11), newest first, each with their group. The outstanding amounts are added
 * by the controller through OutstandingBalances. AssignCustomersToGroup selects "every customer of this filter" through
 * builder().
 */
final readonly class CustomerListQuery
{
    /**
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(?string $search, ?CustomerStatus $status, int $perPage, ?int $groupId = null): LengthAwarePaginator
    {
        return $this->builder($search, $status, $groupId)->with('group')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * @return Builder<Customer>
     */
    public function builder(?string $search, ?CustomerStatus $status, ?int $groupId = null): Builder
    {
        $query = Customer::query();

        if ($search !== null) {
            $this->search($query, $search);
        }

        if ($status instanceof CustomerStatus) {
            $query->where('status', $status);
        }

        if ($groupId !== null) {
            $query->where('customer_group_id', $groupId);
        }

        return $query;
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function search(Builder $query, string $search): Builder
    {
        // Phones are stored as +201XXXXXXXXX; a typed 010… loses its leading zero to match.
        $digits = mb_ltrim((string) preg_replace('/\D/', '', $search), '0');

        return $query->where(function (Builder $query) use ($search, $digits): void {
            $query->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%');

            if ($digits !== '') {
                $query->orWhere('phone', 'like', '%'.$digits.'%');
            }
        });
    }
}
