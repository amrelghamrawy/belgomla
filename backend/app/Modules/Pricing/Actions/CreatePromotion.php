<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Pricing\Data\PromotionData;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Exceptions\PromotionRefused;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Pricing\Queries\PromotionTargetsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * BR-PR15 (V2 Task 12, D90): the merchant creates a promotion, scheduled (the default) or as a draft. Its SKUs are
 * selected now from the filter (not empty) or the explicit list and materialised in `promotion_skus`, so pricing is a
 * lookup (D90 d); the end must come after the start and after now (D90 e). Owns its transaction; audited as
 * `promotion.created`.
 */
final readonly class CreatePromotion
{
    public function __construct(private PromotionTargetsQuery $targets, private AuditLogger $audit) {}

    public function handle(PromotionData $data, ?int $createdBy = null): Promotion
    {
        $startsAt = $data->startsAt ?? Date::now()->toImmutable();
        $endsAt = $data->endsAt ?? $startsAt;

        if ($endsAt->lte($startsAt) || $endsAt->lte(Date::now())) {
            throw PromotionRefused::badWindow();
        }

        return DB::transaction(function () use ($data, $createdBy, $startsAt, $endsAt): Promotion {
            $skuIds = $this->targets->skuIds($data->filter, $data->skuIds);

            $promotion = Promotion::query()->create([
                'name' => (string) $data->name,
                'discount_percent' => (string) $data->discountPercent,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'filter' => $data->filter instanceof ProductFilterData ? $this->targets->stored($data->filter) : null,
                'status' => $data->status ?? PromotionStatus::Scheduled,
                'created_by' => $createdBy,
            ])->refresh();

            $promotion->skus()->sync($skuIds);

            $this->audit->log('promotion.created', $promotion, null, $this->values($promotion, count($skuIds)));

            return $promotion;
        });
    }

    /**
     * What the audit records of a promotion keep.
     *
     * @return array{name: string, discount_percent: string, starts_at: string, ends_at: string, status: string, filter: array<string, mixed>|null, skus: int}
     */
    private function values(Promotion $promotion, int $skus): array
    {
        return [
            'name' => $promotion->name,
            'discount_percent' => $promotion->discount_percent,
            'starts_at' => $this->iso($promotion->starts_at),
            'ends_at' => $this->iso($promotion->ends_at),
            'status' => $promotion->status->value,
            'filter' => $promotion->filter,
            'skus' => $skus,
        ];
    }

    private function iso(CarbonImmutable $moment): string
    {
        return $moment->toIso8601String();
    }
}
