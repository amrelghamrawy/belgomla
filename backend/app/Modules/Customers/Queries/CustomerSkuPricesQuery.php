<?php

declare(strict_types=1);

namespace App\Modules\Customers\Queries;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerSkuPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * BR-PR13 (V2 Task 11): the prices negotiated with one customer for the «أسعار خاصة» tab, newest first, each with its SKU,
 * product and selling unit (to show next to the SKU's own base price). `q` matches the product name or the SKU code.
 */
final readonly class CustomerSkuPricesQuery
{
    public const array RELATIONS = ['sku.product', 'sku.sellingUnit'];

    /**
     * @return LengthAwarePaginator<int, CustomerSkuPrice>
     */
    public function paginate(Customer $customer, ?string $search, int $perPage): LengthAwarePaginator
    {
        $query = CustomerSkuPrice::query()->where('customer_id', $customer->id)->with(self::RELATIONS);

        if ($search !== null) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('sku', fn (Builder $sku): Builder => $sku->where(
                fn (Builder $match): Builder => $match->where('code', 'ilike', $like)->orWhereHas('product', fn (Builder $product): Builder => $product->where('name', 'ilike', $like)),
            ));
        }

        return $query->orderByDesc('updated_at')->orderByDesc('id')->paginate($perPage);
    }

    public function one(CustomerSkuPrice $price): CustomerSkuPrice
    {
        return $price->load(self::RELATIONS);
    }
}
