<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Pricing\Actions\AdvancePromotions;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Models\Promotion;
use Carbon\CarbonImmutable;

/*
| BR-PR15 (V2 Task 12, D90 c): the tick moves stored statuses across every store at once; nothing else changes.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00:00'));
});

function promotionIn(App\Modules\Tenancy\Models\Tenant $tenant, array $attributes): Promotion
{
    return app(App\Modules\Tenancy\TenantContext::class)->runAs($tenant, fn (): Promotion => Promotion::factory()->create($attributes));
}

test('BR-PR15 started promotions become active and finished ones ended, in every store', function (): void {
    $started = promotionIn($this->tenant, ['starts_at' => '2026-10-27 09:59:00', 'ends_at' => '2026-11-01 00:00:00']);
    $otherStore = promotionIn($this->otherTenant(), ['starts_at' => '2026-10-27 10:00:00', 'ends_at' => '2026-11-01 00:00:00']);
    $over = promotionIn($this->tenant, ['status' => 'active', 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-27 10:00:00']);
    $neverStarted = promotionIn($this->tenant, ['starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-20 00:00:00']);

    $moved = app(AdvancePromotions::class)->handle();

    expect($moved)->toBe(['activated' => 2, 'ended' => 2])
        ->and(Promotion::withoutTenantScope()->whereKey([$started->id, $otherStore->id])->pluck('status')->all())->toBe([PromotionStatus::Active, PromotionStatus::Active])
        ->and(Promotion::withoutTenantScope()->whereKey([$over->id, $neverStarted->id])->pluck('status')->all())->toBe([PromotionStatus::Ended, PromotionStatus::Ended]);
});

test('D90 c upcoming, draft and cancelled promotions stay as they are, and nothing is audited', function (): void {
    $upcoming = promotionIn($this->tenant, ['starts_at' => '2026-10-27 10:00:01', 'ends_at' => '2026-11-01 00:00:00']);
    $draft = promotionIn($this->tenant, ['status' => 'draft', 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-20 00:00:00']);
    $cancelled = promotionIn($this->tenant, ['status' => 'cancelled', 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-20 00:00:00']);

    expect(app(AdvancePromotions::class)->handle())->toBe(['activated' => 0, 'ended' => 0])
        ->and(Promotion::withoutTenantScope()->orderBy('id')->pluck('status')->all())->toBe([PromotionStatus::Scheduled, PromotionStatus::Draft, PromotionStatus::Cancelled])
        ->and([$upcoming->id, $draft->id, $cancelled->id])->toHaveCount(3)
        ->and(AuditLog::withoutTenantScope()->count())->toBe(0);
});
