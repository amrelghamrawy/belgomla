<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Pricing\Actions\UpdatePromotion;
use App\Modules\Pricing\Data\PromotionData;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Exceptions\PromotionRefused;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/*
| BR-PR15 (V2 Task 12, D90 d, f): a scheduled promotion on the الأضواء books (by search) that starts tomorrow.
*/

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00:00'));
    $this->first = Product::factory()->withSku()->create(['name' => 'الأضواء علوم'])->skus()->sole();
    $this->outside = Product::factory()->withSku()->create(['name' => 'المعلم عربي'])->skus()->sole();
    $this->promotion = Promotion::factory()->covering($this->first)->upcoming()->create([
        'name' => 'عرض الأضواء',
        'filter' => ['category' => null, 'f' => [], 'q' => 'الأضواء', 'status' => null],
    ]);
});

function promotionChanges(array $changes = []): PromotionData
{
    return new PromotionData(...['name' => null, 'discountPercent' => null, 'startsAt' => null, 'endsAt' => null, ...$changes]);
}

test('BR-PR15 a field sent changes, the rest stays, audited with what changed', function (): void {
    $promotion = app(UpdatePromotion::class)->handle($this->promotion, promotionChanges(['discountPercent' => '20.00']));

    expect($promotion->discount_percent)->toBe('20.00')
        ->and($promotion->name)->toBe('عرض الأضواء')
        ->and(AuditLog::query()->sole()->only(['action', 'old_values', 'new_values']))->toEqual([
            'action' => 'promotion.updated', 'old_values' => ['discount_percent' => '15.00'], 'new_values' => ['discount_percent' => '20.00'],
        ]);
});

test('D90 d every save selects the skus of the stored filter again', function (): void {
    $added = Product::factory()->withSku()->create(['name' => 'الأضواء دراسات'])->skus()->sole();

    $promotion = app(UpdatePromotion::class)->handle($this->promotion, promotionChanges(['name' => 'عرض الأضواء الكبير']));

    expect($promotion->skus()->orderBy('skus.id')->pluck('skus.id')->all())->toBe([$this->first->id, $added->id])
        ->and(AuditLog::query()->sole()->new_values)->toMatchArray(['skus' => 2]);
});

test('D90 d a new sku list replaces the filter', function (): void {
    $promotion = app(UpdatePromotion::class)->handle($this->promotion, promotionChanges(['skuIds' => [$this->outside->id]]));

    expect($promotion->filter)->toBeNull()
        ->and($promotion->skus()->pluck('skus.id')->all())->toBe([$this->outside->id]);
});

test('D90 d a new filter replaces a sku list', function (): void {
    $listed = Promotion::factory()->covering($this->outside)->upcoming()->create();

    $promotion = app(UpdatePromotion::class)->handle($listed, promotionChanges(['filter' => new ProductFilterData(q: 'الأضواء')]));

    expect($promotion->filter)->toEqual(['category' => null, 'f' => [], 'q' => 'الأضواء', 'status' => null])
        ->and($promotion->skus()->pluck('skus.id')->all())->toBe([$this->first->id]);
});

test('D90 f a draft is scheduled through its status', function (): void {
    $this->promotion->update(['status' => PromotionStatus::Draft]);

    expect(app(UpdatePromotion::class)->handle($this->promotion, promotionChanges(['status' => PromotionStatus::Scheduled]))->status)->toBe(PromotionStatus::Scheduled);
});

test('BR-PR15 a save that changes nothing writes nothing', function (): void {
    app(UpdatePromotion::class)->handle($this->promotion, promotionChanges(['name' => 'عرض الأضواء']));

    expect(AuditLog::query()->count())->toBe(0);
});

test('D90 e the window is checked against the stored times', function (): void {
    expect(fn () => app(UpdatePromotion::class)->handle($this->promotion, promotionChanges(['endsAt' => $this->promotion->starts_at])))
        ->toThrow(function (PromotionRefused $e): void {
            expect($e->details)->toHaveKey('ends_at');
        });
});

test('D90 f a promotion that started, ended or was cancelled cannot be edited', function (array $attributes, string $status): void {
    $promotion = Promotion::factory()->covering($this->first)->create($attributes);

    expect(fn () => app(UpdatePromotion::class)->handle($promotion, promotionChanges(['name' => 'جديد'])))
        ->toThrow(function (PromotionRefused $e) use ($status): void {
            expect($e->status)->toBe(409)->and($e->errorCode)->toBe('promotion_locked')->and($e->details)->toBe(['status' => $status]);
        });
})->with([
    'running (scheduled, started)' => [['status' => 'scheduled', 'starts_at' => '2026-10-27 09:00:00', 'ends_at' => '2026-11-01 00:00:00'], 'active'],
    'ended' => [['status' => 'ended', 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-20 00:00:00'], 'ended'],
    'cancelled before it started' => [['status' => 'cancelled', 'starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-05 00:00:00'], 'cancelled'],
]);
