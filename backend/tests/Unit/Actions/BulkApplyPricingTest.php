<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
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
        'skipped_no_cover_price' => [['product_id' => $this->noCover->id, 'sku_id' => $this->noCover->sku->id, 'code' => 'A-3', 'name' => 'علوم ثالثة']],
        'batch_id' => null,
    ])
        ->and($this->priced->sku->refresh()->base_price)->toBe('110.00')
        ->and(SkuPriceTier::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('BR-PR11 apply prices every matching sku off its own cover price and skips those without one', function (): void {
    $result = app(BulkApplyPricing::class)->handle(($this->data)());

    expect($result['affected'])->toBe(2)
        ->and($result['skipped_no_cover_price'])->toHaveCount(1)
        ->and($this->priced->sku->refresh()->only(['base_price', 'base_price_percent']))->toBe(['base_price' => '90.00', 'base_price_percent' => '25.00'])
        ->and($this->other->sku->refresh()->base_price)->toBe('60.00')
        ->and($this->noCover->sku->refresh()->base_price)->toBe('50.00')
        ->and($this->outside->sku->refresh()->base_price)->toBe('100.00');
    $this->assertDatabaseHas('sku_price_tiers', ['sku_id' => $this->priced->sku->id, 'min_quantity' => 10, 'unit_price' => '84.00', 'discount_percent' => '30.00']);
    $this->assertDatabaseHas('sku_price_tiers', ['sku_id' => $this->other->sku->id, 'min_quantity' => 10, 'unit_price' => '56.00']);
});

test('BR-PR11 apply writes one audit batch: the per-sku records and one bulk record', function (): void {
    $result = app(BulkApplyPricing::class)->handle(($this->data)());

    $logs = AuditLog::query()->where('batch_id', $result['batch_id'])->get();

    expect(AuditLog::query()->whereNull('batch_id')->count())->toBe(0)
        ->and($logs->where('action', 'sku.price_changed')->pluck('entity_id')->sort()->values()->all())->toBe([$this->priced->sku->id, $this->other->sku->id])
        ->and($logs->where('action', 'sku.tiers_changed'))->toHaveCount(2)
        ->and($logs->firstWhere('action', 'pricing.bulk_applied')->new_values)->toEqual([
            'base_price_percent' => '25.00',
            'tiers' => [['min_quantity' => 10, 'discount_percent' => '30.00']],
            'moq' => null,
            'affected' => 2,
            'skipped_no_cover_price' => 1,
        ]);
});

test('BR-MOQ01 bulk pricing sets the moq only when one is given', function (): void {
    app(BulkApplyPricing::class)->handle(($this->data)());
    $kept = $this->priced->sku->refresh()->moq;

    app(BulkApplyPricing::class)->handle(($this->data)(['moq' => 12]));

    expect($kept)->toBe(2)
        ->and($this->priced->sku->refresh()->moq)->toBe(12);
});

test('BR-PR03 bulk pricing replaces the tiers of every matching sku', function (): void {
    SkuPriceTier::factory()->for($this->priced->sku)->create(['min_quantity' => 5, 'unit_price' => '100.00']);

    app(BulkApplyPricing::class)->handle(($this->data)(['tiers' => []]));

    expect(SkuPriceTier::query()->where('sku_id', $this->priced->sku->id)->count())->toBe(0);
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

    expect($this->priced->sku->refresh()->base_price)->toBe('110.00')
        ->and(AuditLog::query()->count())->toBe(0);
});
