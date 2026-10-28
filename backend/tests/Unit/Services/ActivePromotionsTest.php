<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Pricing\Services\ActivePromotions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| BR-PR15 (V2 Task 12, D90 a, c): the live promotion of each SKU, read from promotion_skus. The promotion runs
| 2026-11-01 00:00 → 2026-11-08 00:00 Cairo time (22:00 UTC the day before).
*/

beforeEach(function (): void {
    [$this->sku, $this->other] = $this->inTenant(fn (): array => [
        Product::factory()->withSku()->create()->skus()->sole(),
        Product::factory()->withSku()->create()->skus()->sole(),
    ]);
    $this->promotion = $this->inTenant(fn (): Promotion => Promotion::factory()->covering($this->sku)->create([
        'name' => 'عرض نوفمبر',
        'discount_percent' => '15.00',
        'starts_at' => CarbonImmutable::parse('2026-10-31 22:00:00'),
        'ends_at' => CarbonImmutable::parse('2026-11-07 22:00:00'),
    ]));
});

function livePromotionsAt(string $utc, Sku ...$skus): array
{
    return app(ActivePromotions::class)->forSkus(array_map(fn (Sku $sku): int => $sku->id, $skus), CarbonImmutable::parse($utc));
}

test('BR-PR15 D60 the boundaries: live from the start, no longer at the end', function (string $utc, bool $live): void {
    $promotions = $this->inTenant(fn (): array => livePromotionsAt($utc, $this->sku));

    expect(array_key_exists($this->sku->id, $promotions))->toBe($live);
})->with([
    'a second before the start (23:59:59 Cairo)' => ['2026-10-31 21:59:59', false],
    'at the start (midnight Cairo)' => ['2026-10-31 22:00:00', true],
    'a second before the end' => ['2026-11-07 21:59:59', true],
    'at the end' => ['2026-11-07 22:00:00', false],
]);

test('BR-PR15 the live promotion carries its id, name, % and end', function (): void {
    $promotion = $this->inTenant(fn (): array => livePromotionsAt('2026-11-03 10:00:00', $this->sku))[$this->sku->id];

    expect($promotion->promotionId)->toBe($this->promotion->id)
        ->and($promotion->name)->toBe('عرض نوفمبر')
        ->and($promotion->discountPercent)->toBe('15.00')
        ->and($promotion->endsAt->toIso8601String())->toBe('2026-11-07T22:00:00+00:00');
});

test('BR-PR15 a sku outside the promotion has none', function (): void {
    expect($this->inTenant(fn (): array => livePromotionsAt('2026-11-03 10:00:00', $this->other)))->toBe([]);
});

test('D90 c a draft or a cancelled promotion is never live, whatever its times', function (string $state): void {
    $this->inTenant(fn () => $this->promotion->update($state === 'draft' ? ['status' => 'draft'] : ['status' => 'cancelled', 'cancelled_at' => now()]));

    expect($this->inTenant(fn (): array => livePromotionsAt('2026-11-03 10:00:00', $this->sku)))->toBe([]);
})->with(['draft', 'cancelled']);

test('D90 c an active or ended stored status does not decide, the times do', function (): void {
    $this->inTenant(fn () => $this->promotion->update(['status' => 'active']));

    expect($this->inTenant(fn (): array => livePromotionsAt('2026-11-09 10:00:00', $this->sku)))->toBe([]);
});

test('D90 a promotions do not stack: the highest % wins', function (): void {
    $higher = $this->inTenant(fn (): Promotion => Promotion::factory()->covering($this->sku)->percent('25.00')->create([
        'starts_at' => CarbonImmutable::parse('2026-11-02 22:00:00'),
        'ends_at' => CarbonImmutable::parse('2026-11-20 22:00:00'),
    ]));

    $promotions = $this->inTenant(fn (): array => livePromotionsAt('2026-11-03 10:00:00', $this->sku, $this->other));

    expect($promotions)->toHaveCount(1)
        ->and($promotions[$this->sku->id]->promotionId)->toBe($higher->id);
});

test('D90 a on an equal % the oldest promotion wins', function (): void {
    $this->inTenant(fn (): Promotion => Promotion::factory()->covering($this->sku)->percent('15.00')->create([
        'starts_at' => CarbonImmutable::parse('2026-11-02 22:00:00'),
        'ends_at' => CarbonImmutable::parse('2026-11-20 22:00:00'),
    ]));

    expect($this->inTenant(fn (): array => livePromotionsAt('2026-11-03 10:00:00', $this->sku))[$this->sku->id]->promotionId)->toBe($this->promotion->id);
});

test('BR-T04 another store\'s promotion on the same sku id never applies', function (): void {
    $other = $this->otherTenant();
    $this->inTenant(fn (): Promotion => Promotion::factory()->covering($this->other)->create(), $other);

    expect($this->inTenant(fn (): array => livePromotionsAt(now()->toDateTimeString(), $this->other)))->toBe([]);
});

test('BR-PR15 no sku asked, no query', function (): void {
    DB::enableQueryLog();

    $promotions = $this->inTenant(fn (): array => app(ActivePromotions::class)->forSkus([]));

    expect($promotions)->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

test('BR-PR15 several skus are read in one query', function (): void {
    DB::enableQueryLog();

    $this->inTenant(fn (): array => livePromotionsAt('2026-11-03 10:00:00', $this->sku, $this->other));

    expect(DB::getQueryLog())->toHaveCount(1);
});

test('BR-PR15 the sku ids subquery lists the skus on a live promotion now', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00'));

    $ids = $this->inTenant(fn (): array => app(ActivePromotions::class)->skuIdsQuery()->pluck('sku_id')->all());

    expect($ids)->toBe([$this->sku->id]);
});
