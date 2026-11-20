<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Actions\BulkApplyPricing;
use App\Modules\Pricing\Data\BulkPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Exceptions\InvalidPricingInput;
use App\Modules\Pricing\Exceptions\InvalidTiers;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->priced = Product::factory()->withSku(['code' => 'A-1', 'cover_price' => '120.00', 'base_price' => '110.00', 'moq' => 2])->create(['name' => 'علوم أولى']);
    $this->other = Product::factory()->withSku(['code' => 'A-2', 'cover_price' => '80.00', 'base_price' => '80.00'])->create(['name' => 'علوم ثانية']);
    $this->noCover = Product::factory()->withSku(['code' => 'A-3', 'cover_price' => null, 'base_price' => '50.00'])->create(['name' => 'علوم ثالثة']);
    $this->outside = Product::factory()->withSku(['code' => 'B-1', 'cover_price' => '100.00', 'base_price' => '100.00'])->create(['name' => 'رياضيات']);
    $this->unitOf = fn (Product $product): SkuUnit => SkuUnit::query()->where('sku_id', $product->skus()->sole()->id)->where('is_default', true)->sole();
    $this->data = fn (array $changes = []): BulkPricingData => new BulkPricingData(...[
        'filter' => new ProductFilterData(q: 'علوم'),
        'basePricePercent' => '25.00',
        'tiers' => [new TierData(10, discountPercent: '30.00')],
        ...$changes,
    ]);
});

test('BR-PR11 preview reports affected and skipped skus and writes nothing', function (): void {
    $result = app(BulkApplyPricing::class)->handle(($this->data)(), apply: false);

    expect($result)->toBe([
        'affected' => 2,
        'skipped_no_cover_price' => [[
            'product_id' => $this->noCover->id, 'sku_id' => $this->noCover->skus()->sole()->id, 'sku_unit_id' => ($this->unitOf)($this->noCover)->id,
            'code' => 'A-3', 'name' => 'علوم ثالثة', 'unit' => ($this->unitOf)($this->noCover)->unit->name,
        ]],
        'batch_id' => null,
    ])
        ->and(($this->unitOf)($this->priced)->base_price)->toBe('110.00')
        ->and(SkuPriceTier::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('BR-PR11 apply prices every matching sku off its own cover price and skips those without one', function (): void {
    $result = app(BulkApplyPricing::class)->handle(($this->data)());

    expect($result['affected'])->toBe(2)
        ->and($result['skipped_no_cover_price'])->toHaveCount(1)
        ->and(($this->unitOf)($this->priced)->only(['base_price', 'base_price_percent']))->toBe(['base_price' => '90.00', 'base_price_percent' => '25.00'])
        ->and(($this->unitOf)($this->other)->base_price)->toBe('60.00')
        ->and(($this->unitOf)($this->noCover)->base_price)->toBe('50.00')
        ->and(($this->unitOf)($this->outside)->base_price)->toBe('100.00');
    $this->assertDatabaseHas('sku_price_tiers', ['sku_unit_id' => ($this->unitOf)($this->priced)->id, 'min_quantity' => 10, 'unit_price' => '84.00', 'discount_percent' => '30.00']);
    $this->assertDatabaseHas('sku_price_tiers', ['sku_unit_id' => ($this->unitOf)($this->other)->id, 'min_quantity' => 10, 'unit_price' => '56.00']);
});

test('BR-PR11 apply writes one audit batch: the per-sku records and one bulk record', function (): void {
    $result = app(BulkApplyPricing::class)->handle(($this->data)());

    $logs = AuditLog::query()->where('batch_id', $result['batch_id'])->get();

    expect(AuditLog::query()->whereNull('batch_id')->count())->toBe(0)
        ->and($logs->where('action', 'sku.price_changed')->pluck('entity_id')->sort()->values()->all())->toBe([($this->unitOf)($this->priced)->id, ($this->unitOf)($this->other)->id])
        ->and($logs->where('action', 'sku.tiers_changed'))->toHaveCount(2)
        ->and($logs->firstWhere('action', 'pricing.bulk_applied')->new_values)->toEqual([
            'base_price_percent' => '25.00',
            'tiers' => [['min_quantity' => 10, 'discount_percent' => '30.00']],
            'moq' => null,
            'unit_id' => null,
            'affected' => 2,
            'skipped_no_cover_price' => 1,
        ]);
});

test('BR-MOQ01 bulk pricing sets the moq only when one is given', function (): void {
    app(BulkApplyPricing::class)->handle(($this->data)());
    $kept = ($this->unitOf)($this->priced)->moq;

    app(BulkApplyPricing::class)->handle(($this->data)(['moq' => 12]));

    expect($kept)->toBe(2)
        ->and(($this->unitOf)($this->priced)->moq)->toBe(12);
});

test('BR-PR03 bulk pricing replaces the tiers of every matching sku', function (): void {
    SkuPriceTier::factory()->for(($this->unitOf)($this->priced))->create(['min_quantity' => 5, 'unit_price' => '100.00']);

    app(BulkApplyPricing::class)->handle(($this->data)(['tiers' => []]));

    expect(SkuPriceTier::query()->where('sku_unit_id', ($this->unitOf)($this->priced)->id)->count())->toBe(0);
});

test('D91 every active unit of a matching sku is priced off its own cover price', function (): void {
    $carton = $this->addUnit($this->priced->skus()->sole(), ['cover_price' => '1200.00', 'base_price' => '1000.00']);
    $archived = SkuUnit::factory()->archived()->create(['sku_id' => $this->priced->skus()->sole()->id, 'cover_price' => '50.00', 'base_price' => '40.00']);

    $result = app(BulkApplyPricing::class)->handle(($this->data)());

    expect($result['affected'])->toBe(3)
        ->and($carton->refresh()->only(['base_price', 'base_price_percent']))->toBe(['base_price' => '900.00', 'base_price_percent' => '25.00'])
        ->and($archived->refresh()->base_price)->toBe('40.00');
});

test('D91 a store unit filter prices only the units sold in it, skipped ones naming their unit', function (): void {
    $carton = $this->addUnit($this->priced->skus()->sole(), ['cover_price' => '1200.00', 'base_price' => '1000.00']);
    $bare = $this->addUnit($this->other->skus()->sole(), ['unit_id' => $carton->unit_id, 'cover_price' => null]);

    $result = app(BulkApplyPricing::class)->handle(($this->data)(['unitId' => $carton->unit_id]));

    expect($result['affected'])->toBe(1)
        ->and(array_column($result['skipped_no_cover_price'], 'sku_unit_id'))->toBe([$bare->id])
        ->and($carton->refresh()->base_price)->toBe('900.00')
        ->and(($this->unitOf)($this->priced)->base_price)->toBe('110.00')
        ->and(AuditLog::query()->where('action', 'pricing.bulk_applied')->sole()->new_values['unit_id'] ?? null)->toBe($carton->unit_id);
});

test('BR-P12 an empty filter is refused before anything is read or written', function (bool $apply): void {
    expect(fn () => app(BulkApplyPricing::class)->handle(($this->data)(['filter' => new ProductFilterData]), $apply))
        ->toThrow(InvalidPricingInput::class);

    expect(AuditLog::query()->count())->toBe(0);
})->with(['preview' => [false], 'apply' => [true]]);

test('BR-PR05 invalid tiers fail the preview the same way the apply would', function (): void {
    app(BulkApplyPricing::class)->handle(($this->data)(['tiers' => [new TierData(10, discountPercent: '30.00'), new TierData(10, discountPercent: '35.00')]]), apply: false);
})->throws(InvalidTiers::class);

test('D28 a tier percent below the base percent fails the whole apply and rolls everything back', function (): void {
    expect(fn () => app(BulkApplyPricing::class)->handle(($this->data)(['tiers' => [new TierData(10, discountPercent: '10.00')]])))
        ->toThrow(InvalidTiers::class);

    expect(($this->unitOf)($this->priced)->base_price)->toBe('110.00')
        ->and(AuditLog::query()->count())->toBe(0);
});
