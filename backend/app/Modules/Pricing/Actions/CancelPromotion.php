<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Exceptions\PromotionRefused;
use App\Modules\Pricing\Models\Promotion;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * BR-PR15 (V2 Task 12, D90 f): the merchant stops a promotion that has not ended, a running one included: its prices
 * stop at once (checkout reads the status, BR-PR06); orders already placed keep their snapshot (BR-PR07). An ended or
 * cancelled promotion answers `409 promotion_locked`. Owns its transaction; audited as `promotion.cancelled`.
 */
final readonly class CancelPromotion
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Promotion $promotion): Promotion
    {
        return DB::transaction(function () use ($promotion): Promotion {
            $promotion = Promotion::query()->lockForUpdate()->findOrFail($promotion->id);
            $status = $promotion->currentStatus();

            if (! $status->canBeCancelled()) {
                throw PromotionRefused::locked($status);
            }

            $promotion->forceFill(['status' => PromotionStatus::Cancelled, 'cancelled_at' => Date::now()])->save();
            $this->audit->log('promotion.cancelled', $promotion, ['status' => $status->value], ['status' => PromotionStatus::Cancelled->value]);

            return $promotion;
        });
    }
}
