<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Data\PreorderFiltersData;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PreorderState;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/preorders (BR-O14, D88): the store's pre-orders with their customer and lines, in the order they
 * will be converted (oldest `placed_at` first), optionally only those holding a product and only `open` (pending with
 * something left), `converted` or `cancelled` ones.
 */
final readonly class PreordersQuery
{
    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(PreorderFiltersData $filters, int $perPage): LengthAwarePaginator
    {
        $query = Order::query()->where('kind', OrderKind::Preorder)->with(['customer', 'items']);

        if ($filters->productId !== null) {
            $query->whereHas('items', fn (Builder $items): Builder => $items->where('product_id', $filters->productId));
        }

        match ($filters->state) {
            PreorderState::Open => $query->where('status', OrderStatus::Pending)->whereNull('converted_at'),
            PreorderState::Converted => $query->whereNotNull('converted_at'),
            PreorderState::Cancelled => $query->where('status', OrderStatus::Cancelled),
            null => $query,
        };

        return $query->orderBy('placed_at')->orderBy('id')->paginate($perPage);
    }
}
