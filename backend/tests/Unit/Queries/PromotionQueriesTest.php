<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Pricing\Queries\PromotionsQuery;
use App\Modules\Pricing\Queries\PromotionTargetsQuery;
use App\Modules\Tenancy\TenantContext;

/*
| BR-PR15 (V2 Task 12, D90 d): what a promotion covers, its preview, and the dashboard lists.
*/

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->first = Product::factory()->withSku(['code' => 'ADW-1', 'base_price' => '100.00'])->create(['name' => 'الأضواء علوم'])->skus()->sole();
    $this->second = Product::factory()->withSku(['code' => 'ADW-2', 'base_price' => '10.10'])->create(['name' => 'الأضواء رياضيات'])->skus()->sole();
});

test('D90 d the preview counts the selection and prices a sample under the %', function (): void {
    $preview = app(PromotionTargetsQuery::class)->preview(new ProductFilterData(q: 'الأضواء'), null, '25.00', 1);

    expect($preview)->toBe([
        'skus_count' => 2,
        'sample' => [['sku_id' => $this->first->id, 'code' => 'ADW-1', 'product_name' => 'الأضواء علوم', 'base_price' => '100.00', 'promotion_price' => '75.00']],
    ]);
});

test('D90 d without a % the preview leaves the promotion price out', function (): void {
    $preview = app(PromotionTargetsQuery::class)->preview(null, [$this->second->id], null, 10);

    expect($preview['sample'][0]['promotion_price'])->toBeNull()
        ->and($preview['skus_count'])->toBe(1);
});

test('D90 d a stored filter reads back as the same selection', function (): void {
    $targets = app(PromotionTargetsQuery::class);
    $filter = new ProductFilterData(categoryId: 4, fields: ['grade' => '12'], q: 'علوم', status: ProductStatus::Active);
    $promotion = Promotion::factory()->make(['filter' => $targets->stored($filter)]);

    expect($targets->stored($filter))->toBe(['category' => 4, 'f' => ['grade' => '12'], 'q' => 'علوم', 'status' => 'active'])
        ->and($targets->filterOf($promotion))->toEqual($filter)
        ->and($targets->filterOf(Promotion::factory()->make()))->toBeNull();
});

test('D90 d current() lists the skus a promotion covers, ascending', function (): void {
    $promotion = Promotion::factory()->covering($this->second, $this->first)->create();

    expect(app(PromotionTargetsQuery::class)->current($promotion))->toBe([$this->first->id, $this->second->id]);
});

test('BR-PR15 the list is latest start first with sku counts, filtered by status', function (): void {
    $older = Promotion::factory()->covering($this->first)->create(['starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2030-01-01 00:00:00']);
    $newer = Promotion::factory()->covering($this->first, $this->second)->draft()->create(['starts_at' => '2026-10-10 00:00:00', 'ends_at' => '2030-01-01 00:00:00']);
    $this->inTenant(fn () => Promotion::factory()->create(), $this->otherTenant());
    app(TenantContext::class)->set($this->tenant);

    $page = app(PromotionsQuery::class)->paginate(null, 24);
    $drafts = app(PromotionsQuery::class)->paginate(PromotionStatus::Draft, 24);

    expect(collect($page->items())->map(fn (Promotion $promotion): array => [$promotion->id, $promotion->skus_count])->all())->toBe([[$newer->id, 2], [$older->id, 1]])
        ->and(collect($drafts->items())->pluck('id')->all())->toBe([$newer->id]);
});

test('BR-PR15 the detail of an explicit list loads its skus with their products, a filter does not', function (): void {
    $listed = app(PromotionsQuery::class)->detail(Promotion::factory()->covering($this->first)->create());
    $filtered = app(PromotionsQuery::class)->detail(Promotion::factory()->covering($this->first)->create(['filter' => ['category' => null, 'f' => [], 'q' => 'الأضواء', 'status' => null]]));

    expect($listed->skus_count)->toBe(1)
        ->and($listed->relationLoaded('skus'))->toBeTrue()
        ->and($listed->skus->first()?->relationLoaded('product'))->toBeTrue()
        ->and($filtered->relationLoaded('skus'))->toBeFalse();
});
