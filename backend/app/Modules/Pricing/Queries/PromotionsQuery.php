<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Queries;

use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Models\Promotion;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * BR-PR15 (V2 Task 12): the dashboard's promotions, latest start first, each with how many SKUs it covers; `?status=`
 * filters by the stored status (moved every minute by `pricing:promotions-tick`). One promotion with its SKU count, and
 * its explicit SKU list with their products when it has one (the edit form shows them).
 */
final readonly class PromotionsQuery
{
    /**
     * @return LengthAwarePaginator<int, Promotion>
     */
    public function paginate(?PromotionStatus $status, int $perPage): LengthAwarePaginator
    {
        $query = Promotion::query()->withCount('skus');

        if ($status instanceof PromotionStatus) {
            $query->where('status', $status);
        }

        return $query
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function detail(Promotion $promotion): Promotion
    {
        $promotion->loadCount('skus');

        if ($promotion->filter === null) {
            $promotion->load(['skus' => fn (BelongsToMany $skus): BelongsToMany => $skus->with('product')->orderBy('skus.id')]);
        }

        return $promotion;
    }
}
