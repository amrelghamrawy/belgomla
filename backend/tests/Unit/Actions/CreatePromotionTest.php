<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Pricing\Actions\CreatePromotion;
use App\Modules\Pricing\Data\PromotionData;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Exceptions\InvalidPricingInput;
use App\Modules\Pricing\Exceptions\PromotionRefused;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/*
| BR-PR15 (V2 Task 12, D90 d, e): two الأضواء books and one other book; promotions selected by search or by sku ids.
*/

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00:00'));
    $this->first = Product::factory()->withSku(['code' => 'ADW-1'])->create(['name' => 'الأضواء علوم'])->sku()->sole();
    $this->second = Product::factory()->withSku(['code' => 'ADW-2'])->create(['name' => 'الأضواء رياضيات'])->sku()->sole();
    $this->outside = Product::factory()->withSku(['code' => 'MOA-1'])->create(['name' => 'المعلم عربي'])->sku()->sole();
    $this->data = fn (array $changes = []): PromotionData => new PromotionData(...[
        'name' => 'عرض الأضواء',
        'discountPercent' => '15.00',
        'startsAt' => CarbonImmutable::parse('2026-10-31 22:00:00'),
        'endsAt' => CarbonImmutable::parse('2026-11-30 22:00:00'),
        'filter' => new ProductFilterData(q: 'الأضواء'),
        ...$changes,
    ]);
});

test('BR-PR15 D90 d a promotion by filter materialises the skus it selects, scheduled', function (): void {
    $promotion = app(CreatePromotion::class)->handle(($this->data)());

    expect($promotion->status)->toBe(PromotionStatus::Scheduled)
        ->and($promotion->filter)->toEqual(['category' => null, 'f' => [], 'q' => 'الأضواء', 'status' => null])
        ->and($promotion->skus()->orderBy('skus.id')->pluck('skus.id')->all())->toBe([$this->first->id, $this->second->id]);
});

test('BR-PR15 a promotion by an explicit sku list keeps exactly those skus and no filter', function (): void {
    $promotion = app(CreatePromotion::class)->handle(($this->data)(['filter' => null, 'skuIds' => [$this->outside->id]]));

    expect($promotion->filter)->toBeNull()
        ->and($promotion->skus()->pluck('skus.id')->all())->toBe([$this->outside->id]);
});

test('D90 f a promotion can be saved as a draft, with who made it', function (): void {
    $owner = $this->makeOwner();

    $promotion = app(CreatePromotion::class)->handle(($this->data)(['status' => PromotionStatus::Draft]), $owner->id);

    expect($promotion->status)->toBe(PromotionStatus::Draft)
        ->and($promotion->created_by)->toBe($owner->id)
        ->and($promotion->skus()->count())->toBe(2);
});

test('BR-PR15 the creation is audited with the window and the sku count', function (): void {
    $promotion = app(CreatePromotion::class)->handle(($this->data)());

    expect(AuditLog::query()->sole()->only(['action', 'entity_type', 'entity_id', 'new_values']))->toEqual([
        'action' => 'promotion.created', 'entity_type' => 'promotions', 'entity_id' => $promotion->id,
        'new_values' => [
            'name' => 'عرض الأضواء', 'discount_percent' => '15.00', 'starts_at' => '2026-10-31T22:00:00+00:00', 'ends_at' => '2026-11-30T22:00:00+00:00',
            'status' => 'scheduled', 'filter' => ['category' => null, 'f' => [], 'q' => 'الأضواء', 'status' => null], 'skus' => 2,
        ],
    ]);
});

test('BR-P12 D90 d an empty filter is refused', function (): void {
    expect(fn () => app(CreatePromotion::class)->handle(($this->data)(['filter' => new ProductFilterData])))
        ->toThrow(function (InvalidPricingInput $e): void {
            expect($e->status)->toBe(422)->and($e->details)->toHaveKey('filter');
        });

    expect(Promotion::query()->count())->toBe(0);
});

test('D90 d a selection without any sku is refused and nothing is written', function (array $changes, string $field): void {
    expect(fn () => app(CreatePromotion::class)->handle(($this->data)($changes)))
        ->toThrow(function (PromotionRefused $e) use ($field): void {
            expect($e->status)->toBe(422)->and($e->details)->toHaveKey($field);
        });

    expect(Promotion::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'a filter that matches nothing' => [['filter' => new ProductFilterData(q: 'لا يوجد')], 'filter'],
    'an empty list' => [['filter' => null, 'skuIds' => []], 'sku_ids'],
]);

test('BR-T04 a sku of another store refuses the whole list', function (): void {
    $foreign = $this->inTenant(fn () => Product::factory()->withSku()->create()->sku()->sole(), $this->otherTenant());
    app(TenantContext::class)->set($this->tenant);

    expect(fn () => app(CreatePromotion::class)->handle(($this->data)(['filter' => null, 'skuIds' => [$this->first->id, $foreign->id]])))
        ->toThrow(function (PromotionRefused $e): void {
            expect($e->details)->toHaveKey('sku_ids');
        });
});

test('D90 e the end must come after the start and after now', function (string $starts, string $ends): void {
    expect(fn () => app(CreatePromotion::class)->handle(($this->data)(['startsAt' => CarbonImmutable::parse($starts), 'endsAt' => CarbonImmutable::parse($ends)])))
        ->toThrow(function (PromotionRefused $e): void {
            expect($e->status)->toBe(422)->and($e->details)->toHaveKey('ends_at');
        });
})->with([
    'end before start' => ['2026-11-05 00:00:00', '2026-11-01 00:00:00'],
    'end equal to start' => ['2026-11-05 00:00:00', '2026-11-05 00:00:00'],
    'already over' => ['2026-10-01 00:00:00', '2026-10-27 09:59:59'],
]);

test('D90 c a promotion may start in the past, it is live at once', function (): void {
    $promotion = app(CreatePromotion::class)->handle(($this->data)(['startsAt' => CarbonImmutable::parse('2026-10-20 00:00:00')]));

    expect($promotion->currentStatus())->toBe(PromotionStatus::Active);
});

test('D90 a failing step rolls the promotion back', function (): void {
    Promotion::created(fn () => throw new RuntimeException('boom'));

    expect(fn () => app(CreatePromotion::class)->handle(($this->data)()))->toThrow(RuntimeException::class);

    expect(Promotion::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});
