<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Http\Resources\BulkPricingResultResource;
use App\Modules\Pricing\Http\Resources\SkuPricingResource;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('the sku pricing resource key set and money strings', function (): void {
    $sku = Sku::factory()->withCoverPrice('120', '25')->create(['code' => 'ADW-1', 'base_price' => '90', 'moq' => 5]);
    SkuPriceTier::factory()->for($sku)->percent('30')->create(['min_quantity' => 10, 'unit_price' => '84']);
    SkuPriceTier::factory()->for($sku)->create(['min_quantity' => 50, 'unit_price' => '80']);

    $data = rendered(new SkuPricingResource($sku->refresh()->load('tiers')), dashboardRequest());

    expect($data)->toBe([
        'product_id' => $sku->product_id,
        'sku_id' => $sku->id,
        'code' => 'ADW-1',
        'cover_price' => '120.00',
        'base_price' => '90.00',
        'base_price_percent' => '25.00',
        'discount_percent' => '25.00',
        'moq' => 5,
        'tiers' => [
            ['min_quantity' => 10, 'unit_price' => '84.00', 'discount_percent' => '30.00'],
            ['min_quantity' => 50, 'unit_price' => '80.00', 'discount_percent' => null],
        ],
    ]);
});

test('BR-PR11 the bulk result carries the batch id only once applied', function (): void {
    $skipped = [['product_id' => 1, 'sku_id' => 2, 'code' => 'A-3', 'name' => 'علوم']];

    $applied = rendered(new BulkPricingResultResource(['affected' => 2, 'skipped_no_cover_price' => $skipped, 'batch_id' => 'b-1']), dashboardRequest());
    $preview = rendered(new BulkPricingResultResource(['affected' => 2, 'skipped_no_cover_price' => $skipped, 'batch_id' => null]), dashboardRequest());

    expect($applied)->toBe(['affected' => 2, 'skipped_no_cover_price' => $skipped, 'batch_id' => 'b-1'])
        ->and($preview)->toBe(['affected' => 2, 'skipped_no_cover_price' => $skipped]);
});

test('BR-PR15 the promotion resource key set, the status as of now and the target', function (): void {
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-11-01 12:00:00'));
    $sku = Sku::factory()->create(['code' => 'ADW-1']);
    $promotion = App\Modules\Pricing\Models\Promotion::factory()->covering($sku)->create([
        'name' => 'عرض', 'discount_percent' => '15', 'starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-02 00:00:00',
    ]);

    $data = rendered(new App\Modules\Pricing\Http\Resources\PromotionResource(app(App\Modules\Pricing\Queries\PromotionsQuery::class)->detail($promotion)), dashboardRequest());

    expect($data)->toBe([
        'id' => $promotion->id,
        'name' => 'عرض',
        'discount_percent' => '15.00',
        'starts_at' => '2026-11-01T00:00:00+00:00',
        'ends_at' => '2026-11-02T00:00:00+00:00',
        'status' => 'active',
        'target' => 'skus',
        'filter' => null,
        'skus_count' => 1,
        'skus' => [['sku_id' => $sku->id, 'code' => 'ADW-1', 'product_name' => $sku->product->name]],
        'cancelled_at' => null,
        'created_at' => '2026-11-01T12:00:00+00:00',
    ]);
});

test('BR-PR15 a promotion by filter shows its filter and no sku list', function (): void {
    $promotion = App\Modules\Pricing\Models\Promotion::factory()->create(['filter' => ['category' => 2, 'f' => [], 'q' => null, 'status' => null]]);

    $data = rendered(new App\Modules\Pricing\Http\Resources\PromotionResource($promotion), dashboardRequest());

    expect($data['target'])->toBe('filter')
        ->and($data['filter'])->toEqual(['category' => 2, 'f' => [], 'q' => null, 'status' => null])
        ->and($data['skus_count'])->toBe(0)
        ->and($data)->not->toHaveKey('skus');
});

test('BR-PR15 the promotion preview resource', function (): void {
    $preview = ['skus_count' => 1, 'sample' => [['sku_id' => 1, 'code' => 'A', 'product_name' => 'ك', 'base_price' => '10.00', 'promotion_price' => null]]];

    expect(rendered(new App\Modules\Pricing\Http\Resources\PromotionPreviewResource($preview), dashboardRequest()))->toBe($preview);
});
