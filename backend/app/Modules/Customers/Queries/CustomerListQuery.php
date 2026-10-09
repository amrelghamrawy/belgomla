<?php

declare(strict_types=1);

namespace App\Modules\Customers\Queries;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Dashboard customer list (08-api-contract.md §8): `q` matches the name or the phone digits, `status` filters,
 * newest first. The outstanding amounts are added by the controller through OutstandingBalances.
 */
final readonly class CustomerListQuery
{
    /**
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(?string $search, ?CustomerStatus $status, int $perPage): LengthAwarePaginator
    {
        $query = Customer::query()->orderByDesc('id');

        if ($search !== null) {
            $this->search($query, $search);
        }

        if ($status instanceof CustomerStatus) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
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
