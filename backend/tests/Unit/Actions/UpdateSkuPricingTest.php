<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Actions\UpdateSkuPricing;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Exceptions\InvalidTiers;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->sku = Sku::factory()->withCoverPrice('120.00')->create(['base_price' => '100.00', 'moq' => 5]);
    SkuPriceTier::factory()->for($this->sku)->create(['min_quantity' => 10, 'unit_price' => '95.00']);
    $this->same = fn (array $changes = []): SkuPricingData => new SkuPricingData(...[
        'basePrice' => '100.00', 'moq' => 5, 'tiers' => [new TierData(10, '95.00')], 'coverPrice' => '120.00', ...$changes,
    ]);
});

test('BR-PR03 the tiers are replaced as a whole and returned loaded', function (): void {
    $sku = app(UpdateSkuPricing::class)->handle($this->sku, ($this->same)(['tiers' => [new TierData(20, '90.00'), new TierData(100, '80.00')]]));

    expect($sku->relationLoaded('tiers'))->toBeTrue()
        ->and($sku->tiers->pluck('unit_price', 'min_quantity')->all())->toBe([20 => '90.00', 100 => '80.00'])
        ->and(SkuPriceTier::query()->where('sku_id', $this->sku->id)->count())->toBe(2);
});

test('BR-PR10 percentages are stored as absolute prices plus the percent used', function (): void {
    app(UpdateSkuPricing::class)->handle($this->sku, ($this->same)(['basePrice' => null, 'basePricePercent' => '25.00', 'tiers' => [new TierData(10, discountPercent: '30.00')]]));

    $this->assertDatabaseHas('skus', ['id' => $this->sku->id, 'base_price' => '90.00', 'base_price_percent' => '25.00']);
    $this->assertDatabaseHas('sku_price_tiers', ['sku_id' => $this->sku->id, 'min_quantity' => 10, 'unit_price' => '84.00', 'discount_percent' => '30.00']);
});

test('BR-PR05 a duplicate threshold is a 422 invalid_tiers and nothing changes', function (): void {
    try {
        app(UpdateSkuPricing::class)->handle($this->sku, ($this->same)(['basePrice' => '99.00', 'tiers' => [new TierData(10, '90.00'), new TierData(10, '85.00')]]));
        $this->fail('Expected InvalidTiers');
    } catch (InvalidTiers $e) {
        expect($e->status)->toBe(422)
            ->and($e->errorCode)->toBe('invalid_tiers')
            ->and($e->getMessage())->toBe('شرائح الأسعار غير صحيحة')
            ->and($e->details)->toBe(['tiers' => ['الحد الأدنى 10 مكرر في أكثر من شريحة']]);
    }

    expect($this->sku->refresh()->base_price)->toBe('100.00')
        ->and(SkuPriceTier::query()->pluck('unit_price', 'min_quantity')->all())->toBe([10 => '95.00'])
        ->and(AuditLog::query()->count())->toBe(0);
});

test('BR-L price, tiers and moq changes are separate audit records with only what changed', function (): void {
    app(UpdateSkuPricing::class)->handle($this->sku, ($this->same)(['basePrice' => '98.00', 'moq' => 10, 'tiers' => [new TierData(10, '92.00')]]));

    $logs = AuditLog::query()->get()->keyBy('action');

    expect($logs->keys()->sort()->values()->all())->toBe(['sku.moq_changed', 'sku.price_changed', 'sku.tiers_changed'])
        ->and($logs->pluck('entity_type')->unique()->all())->toBe(['skus'])
        ->and($logs->pluck('entity_id')->unique()->all())->toBe([$this->sku->id])
        ->and($logs['sku.price_changed']->old_values)->toEqual(['base_price' => '100.00'])
        ->and($logs['sku.price_changed']->new_values)->toEqual(['base_price' => '98.00'])
        ->and($logs['sku.tiers_changed']->old_values)->toEqual(['tiers' => [['min_quantity' => 10, 'unit_price' => '95.00', 'discount_percent' => null]]])
        ->and($logs['sku.tiers_changed']->new_values)->toEqual(['tiers' => [['min_quantity' => 10, 'unit_price' => '92.00', 'discount_percent' => null]]])
        ->and($logs['sku.moq_changed']->old_values)->toEqual(['moq' => 5])
        ->and($logs['sku.moq_changed']->new_values)->toEqual(['moq' => 10]);
});

test('BR-L a cover price change alone is audited as a price change', function (): void {
    app(UpdateSkuPricing::class)->handle($this->sku, ($this->same)(['coverPrice' => '130.00']));

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('sku.price_changed')
        ->and($log->old_values)->toEqual(['cover_price' => '120.00'])
        ->and($log->new_values)->toEqual(['cover_price' => '130.00']);
});

test('BR-L sending the same prices again audits nothing and keeps the tier rows', function (): void {
    $tierId = SkuPriceTier::query()->sole()->id;

    app(UpdateSkuPricing::class)->handle($this->sku, ($this->same)());

    expect(AuditLog::query()->count())->toBe(0)
        ->and(SkuPriceTier::query()->sole()->id)->toBe($tierId);
});

test('BR-PR11 a batch id is written on every record of the change', function (): void {
    app(UpdateSkuPricing::class)->handle($this->sku, ($this->same)(['basePrice' => '98.00', 'moq' => 6]), 'b6a4c1a0-0000-4000-8000-000000000001');

    expect(AuditLog::query()->pluck('batch_id')->unique()->all())->toBe(['b6a4c1a0-0000-4000-8000-000000000001']);
});

test('a sku not saved yet is saved with its prices and tiers and left to its creator to audit', function (): void {
    $product = Product::factory()->create();
    $basics = $this->catalogBasics();
    $sku = $product->skus()->make(['code' => 'NEW-1', 'selling_unit_id' => $basics['carton']->id, 'base_unit_id' => $basics['piece']->id, 'conversion_factor' => 1]);

    app(UpdateSkuPricing::class)->handle($sku, new SkuPricingData('50.00', moq: 2, tiers: [new TierData(10, '45.00')]));

    expect($sku->exists)->toBeTrue()
        ->and($sku->refresh()->base_price)->toBe('50.00')
        ->and($sku->tiers->pluck('unit_price', 'min_quantity')->all())->toBe([10 => '45.00'])
        ->and(AuditLog::query()->count())->toBe(0);
});

test('a failing tier insert rolls back the sku prices and writes no audit', function (): void {
    expect(fn () => app(UpdateSkuPricing::class)->handle($this->sku, ($this->same)(['basePrice' => '98.00', 'tiers' => [new TierData(10, '90.00', '150.00')]])))
        ->toThrow(QueryException::class, 'sku_price_tiers_discount_percent_range');

    expect($this->sku->refresh()->base_price)->toBe('100.00')
        ->and(SkuPriceTier::query()->pluck('unit_price', 'min_quantity')->all())->toBe([10 => '95.00'])
        ->and(AuditLog::query()->count())->toBe(0);
});
