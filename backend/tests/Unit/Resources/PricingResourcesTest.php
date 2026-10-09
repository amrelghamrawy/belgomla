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
