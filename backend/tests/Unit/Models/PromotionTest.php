<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('to array', function (): void {
    $promotion = $this->inTenant(fn () => Promotion::factory()->create()->fresh());

    expect(array_keys($promotion?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'name', 'discount_percent', 'starts_at', 'ends_at', 'filter', 'status', 'created_by', 'cancelled_at', 'created_at', 'updated_at',
    ]);
});

test('casts: the percent as a 2-decimal string, the window as dates, the filter as an array, the status enum', function (): void {
    $promotion = $this->inTenant(fn () => Promotion::factory()->create([
        'discount_percent' => 12.5,
        'filter' => ['category' => 3, 'f' => [], 'q' => null, 'status' => null],
        'starts_at' => '2026-11-01 00:00:00',
        'ends_at' => '2026-11-02 00:00:00',
    ])->fresh());

    expect($promotion?->discount_percent)->toBe('12.50')
        ->and($promotion?->starts_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($promotion?->ends_at->toDateTimeString())->toBe('2026-11-02 00:00:00')
        ->and($promotion?->filter)->toEqual(['category' => 3, 'f' => [], 'q' => null, 'status' => null])
        ->and($promotion?->status)->toBe(PromotionStatus::Scheduled);
});

test('a new promotion is scheduled', function (): void {
    expect((new Promotion)->status)->toBe(PromotionStatus::Scheduled);
});

test('relations: the skus it covers', function (): void {
    [$promotion, $sku] = $this->inTenant(function (): array {
        $sku = Sku::factory()->create();

        return [Promotion::factory()->covering($sku)->create(), $sku];
    });

    expect($this->inTenant(fn (): array => $promotion->skus()->pluck('skus.id')->all()))->toBe([$sku->id]);
});

test('D90 c the current status follows the times while scheduled or active', function (string $status, string $at, PromotionStatus $expected): void {
    $promotion = new Promotion(['status' => $status, 'starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-02 00:00:00']);

    expect($promotion->currentStatus(CarbonImmutable::parse($at)))->toBe($expected);
})->with([
    'scheduled, before the start' => ['scheduled', '2026-10-31 23:59:59', PromotionStatus::Scheduled],
    'scheduled, at the start' => ['scheduled', '2026-11-01 00:00:00', PromotionStatus::Active],
    'active, at the end' => ['active', '2026-11-02 00:00:00', PromotionStatus::Ended],
    'draft stays draft' => ['draft', '2026-11-01 12:00:00', PromotionStatus::Draft],
    'cancelled stays cancelled' => ['cancelled', '2026-11-01 12:00:00', PromotionStatus::Cancelled],
]);

test('D90 c the current status reads the clock by default', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00'));

    expect((new Promotion(['status' => 'scheduled', 'starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-02 00:00:00']))->currentStatus())->toBe(PromotionStatus::Active);
});

test('D90 b e the database refuses a % outside 0–90 and an end before the start', function (array $attributes): void {
    $this->inTenant(fn () => Promotion::factory()->create($attributes));
})->with([
    'zero %' => [['discount_percent' => '0.00']],
    '90 %' => [['discount_percent' => '90.00']],
    'end before start' => [['starts_at' => '2026-11-02 00:00:00', 'ends_at' => '2026-11-01 00:00:00']],
])->throws(QueryException::class);

test('D90 the database refuses an unknown status', function (): void {
    $promotion = $this->inTenant(fn () => Promotion::factory()->create());

    DB::table('promotions')->where('id', $promotion->id)->update(['status' => 'paused']);
})->throws(QueryException::class);

test('D90 g an order line names a promotion exactly when its price came from one', function (): void {
    $this->inTenant(function (): void {
        $promotion = Promotion::factory()->create();
        $item = App\Modules\Orders\Models\OrderItem::factory()->create();

        DB::table('order_items')->where('id', $item->id)->update(['promotion_id' => $promotion->id]);
    });
})->throws(QueryException::class);

test('BR-T03 a promotion created in tenant context gets that tenant id', function (): void {
    expect($this->inTenant(fn () => Promotion::factory()->create())->tenant_id)->toBe($this->tenant->id);
});

test('BR-T03 creating a promotion without a tenant in context throws', function (): void {
    Promotion::factory()->create();
})->throws(RuntimeException::class, 'without a tenant in context');

test('BR-T03 promotion queries without a tenant in context return nothing, in context never another tenant\'s', function (): void {
    $this->inTenant(fn () => Promotion::factory()->create(), $this->otherTenant());
    app(TenantContext::class)->clear();

    expect(Promotion::query()->count())->toBe(0)
        ->and($this->inTenant(fn () => Promotion::query()->count()))->toBe(0);
});
