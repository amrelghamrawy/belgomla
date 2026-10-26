<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Models\Promotion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * BR-PR15 (V2 Task 12, D90 c), `pricing:promotions-tick`: moves the stored status of every store's promotions past their
 * boundaries, scheduled → active once started and scheduled/active → ended once over. Prices never wait for it (they read
 * the times), so it only keeps the dashboard's lists and filters right. Two set-based updates, each atomic on its own; not
 * audited (nobody acted).
 */
final readonly class AdvancePromotions
{
    /**
     * @return array{activated: int, ended: int}
     */
    public function handle(?CarbonImmutable $at = null): array
    {
        $at ??= Date::now()->toImmutable();

        // CLI only, across every store at once: the tick has no tenant context and touches nothing but the status.
        $ended = Promotion::withoutTenantScope()
            ->whereIn('status', [PromotionStatus::Scheduled, PromotionStatus::Active])
            ->where('ends_at', '<=', $at)
            ->update(['status' => PromotionStatus::Ended, 'updated_at' => $at]);

        $activated = Promotion::withoutTenantScope()
            ->where('status', PromotionStatus::Scheduled)
            ->where('starts_at', '<=', $at)
            ->update(['status' => PromotionStatus::Active, 'updated_at' => $at]);

        return ['activated' => $activated, 'ended' => $ended];
    }
}
