<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Pricing\Actions\CancelPromotion;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Exceptions\PromotionRefused;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Pricing\Services\ActivePromotions;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00:00'));
    $this->sku = Product::factory()->withSku()->create()->sku()->sole();
});

test('D90 f a running promotion is cancelled at once: its prices stop, audited', function (): void {
    $promotion = Promotion::factory()->covering($this->sku)->create();

    $cancelled = app(CancelPromotion::class)->handle($promotion);

    expect($cancelled->status)->toBe(PromotionStatus::Cancelled)
        ->and($cancelled->cancelled_at?->toIso8601String())->toBe('2026-10-27T10:00:00+00:00')
        ->and(app(ActivePromotions::class)->forSkus([$this->sku->id]))->toBe([])
        ->and(AuditLog::query()->sole()->only(['action', 'old_values', 'new_values']))->toEqual([
            'action' => 'promotion.cancelled', 'old_values' => ['status' => 'active'], 'new_values' => ['status' => 'cancelled'],
        ]);
});

test('D90 f a draft or a promotion that has not started can be cancelled too', function (string $state): void {
    $promotion = Promotion::factory()->covering($this->sku)->{$state}()->create();

    expect(app(CancelPromotion::class)->handle($promotion)->status)->toBe(PromotionStatus::Cancelled);
})->with(['draft', 'upcoming']);

test('D90 f an ended or cancelled promotion cannot be cancelled', function (string $state): void {
    $promotion = Promotion::factory()->covering($this->sku)->{$state}()->create();

    expect(fn () => app(CancelPromotion::class)->handle($promotion))
        ->toThrow(function (PromotionRefused $e) use ($state): void {
            expect($e->status)->toBe(409)->and($e->errorCode)->toBe('promotion_locked')->and($e->details)->toBe(['status' => $state]);
        });

    expect(AuditLog::query()->count())->toBe(0);
})->with(['ended', 'cancelled']);

test('D90 c a scheduled promotion past its end reads ended before the tick and cannot be cancelled', function (): void {
    $promotion = Promotion::factory()->covering($this->sku)->create(['starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-20 00:00:00']);

    expect(fn () => app(CancelPromotion::class)->handle($promotion))->toThrow(PromotionRefused::class);
});
