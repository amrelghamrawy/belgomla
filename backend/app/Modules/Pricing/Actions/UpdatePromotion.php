<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Pricing\Data\PromotionData;
use App\Modules\Pricing\Exceptions\PromotionRefused;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Pricing\Queries\PromotionTargetsQuery;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * BR-PR15 (V2 Task 12, D90 f): the merchant edits a draft, or a scheduled promotion that has not started yet
 * (`409 promotion_locked` otherwise, judged under the row lock). A field left out stays; the window is checked on the
 * result (D90 e). Every save selects the SKUs again (D90 d): from a new filter or list when one is sent, else from the
 * stored filter, else the stored list. Owns its transaction; audited as `promotion.updated` with what changed (nothing
 * changed, nothing written).
 */
final readonly class UpdatePromotion
{
    public function __construct(private PromotionTargetsQuery $targets, private AuditLogger $audit) {}

    public function handle(Promotion $promotion, PromotionData $data): Promotion
    {
        return DB::transaction(function () use ($promotion, $data): Promotion {
            $promotion = Promotion::query()->lockForUpdate()->findOrFail($promotion->id);
            $status = $promotion->currentStatus();

            if (! $status->isEditable()) {
                throw PromotionRefused::locked($status);
            }

            $before = $this->targets->current($promotion);
            $old = $this->values($promotion, $before);
            $skuIds = $this->skuIds($promotion, $data, $before);

            $promotion->fill(array_filter([
                'name' => $data->name,
                'discount_percent' => $data->discountPercent,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
                'status' => $data->status,
            ], fn (mixed $value): bool => $value !== null));

            if ($data->filter instanceof ProductFilterData) {
                $promotion->filter = $this->targets->stored($data->filter);
            } elseif ($data->skuIds !== null) {
                $promotion->filter = null;
            }

            if ($promotion->ends_at->lte($promotion->starts_at) || $promotion->ends_at->lte(Date::now())) {
                throw PromotionRefused::badWindow();
            }

            if (! $promotion->isDirty() && $skuIds === $before) {
                return $promotion;
            }

            $promotion->save();
            $promotion->skus()->sync($skuIds);
            $this->audit->log('promotion.updated', $promotion, $old, $this->values($promotion->refresh(), $skuIds));

            return $promotion;
        });
    }

    /**
     * @param  list<int>  $before
     * @return list<int>
     */
    private function skuIds(Promotion $promotion, PromotionData $data, array $before): array
    {
        if ($data->filter instanceof ProductFilterData || $data->skuIds !== null) {
            return $this->targets->skuIds($data->filter, $data->skuIds);
        }

        $stored = $this->targets->filterOf($promotion);

        return $stored instanceof ProductFilterData ? $this->targets->skuIds($stored, null) : $before;
    }

    /**
     * @param  list<int>  $skuIds
     * @return array{name: string, discount_percent: string, starts_at: string, ends_at: string, status: string, filter: array<string, mixed>|null, skus: int}
     */
    private function values(Promotion $promotion, array $skuIds): array
    {
        return [
            'name' => $promotion->name,
            'discount_percent' => $promotion->discount_percent,
            'starts_at' => $promotion->starts_at->toIso8601String(),
            'ends_at' => $promotion->ends_at->toIso8601String(),
            'status' => $promotion->status->value,
            'filter' => $promotion->filter,
            'skus' => count($skuIds),
        ];
    }
}
