<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\SaveSkuUnits;
use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Tenancy\TenantContext;
use App\Support\ApiException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->piece = Unit::factory()->create(['name' => 'قطعة']);
    $this->carton = Unit::factory()->create(['name' => 'كرتونة']);
    $this->sku = Sku::factory()->create(['selling_unit_id' => $this->piece->id, 'base_price' => '10.00']);
    $this->line = fn (Unit $unit, int $factor, string $price, bool $default = false, ?int $id = null, ?array $tiers = null): SkuUnitData => new SkuUnitData($id, $unit->id, $factor, $default, new SkuPricingData($price, tiers: $tiers));
    $this->units = fn (): array => SkuUnit::query()->where('sku_id', $this->sku->id)->orderBy('id')->get()
        ->map(fn (SkuUnit $unit): array => [$unit->unit_id, $unit->conversion_factor, $unit->base_price, $unit->is_default, $unit->status->value, $unit->sort_order])->all();
});

test('D91 the units are written in the order sent, the pieces kept by its unit and the carton added', function (): void {
    app(SaveSkuUnits::class)->handle($this->sku, [($this->line)($this->piece, 1, '11.00', true), ($this->line)($this->carton, 48, '480.00')]);

    expect(($this->units)())->toBe([
        [$this->piece->id, 1, '11.00', true, 'active', 0],
        [$this->carton->id, 48, '480.00', false, 'active', 1],
    ]);
});

test('D91 a unit sent with its id is updated, even into another store unit', function (): void {
    $id = $this->defaultUnitId($this->sku);

    app(SaveSkuUnits::class)->handle($this->sku, [($this->line)($this->carton, 12, '115.00', true, $id)]);

    expect(($this->units)())->toBe([[$this->carton->id, 12, '115.00', true, 'active', 0]])
        ->and(SkuUnit::query()->sole()->id)->toBe($id);
});

test('D91 a unit left out is archived, not deleted, and the default moves to a unit sent', function (): void {
    app(SaveSkuUnits::class)->handle($this->sku, [($this->line)($this->carton, 12, '115.00', true)]);

    expect(($this->units)())->toBe([
        [$this->piece->id, 1, '10.00', false, 'archived', 0],
        [$this->carton->id, 12, '115.00', true, 'active', 0],
    ]);
});

test('D91 a unit sent again by its store unit comes back from the archive with the same row', function (): void {
    $id = $this->defaultUnitId($this->sku);
    app(SaveSkuUnits::class)->handle($this->sku, [($this->line)($this->carton, 12, '115.00', true)]);

    app(SaveSkuUnits::class)->handle($this->sku, [($this->line)($this->piece, 1, '9.00', true), ($this->line)($this->carton, 12, '115.00')]);

    expect(SkuUnit::query()->where('unit_id', $this->piece->id)->sole()->only(['id', 'status', 'is_default', 'base_price']))
        ->toEqual(['id' => $id, 'status' => App\Modules\Catalog\Enums\ArchiveStatus::Active, 'is_default' => true, 'base_price' => '9.00'])
        ->and(SkuUnit::query()->count())->toBe(2);
});

test('D91 switching the default between existing units keeps exactly one default', function (): void {
    $piece = $this->defaultUnitId($this->sku);
    $carton = $this->addUnit($this->sku, ['unit_id' => $this->carton->id]);

    app(SaveSkuUnits::class)->handle($this->sku, [($this->line)($this->piece, 1, '10.00', id: $piece), ($this->line)($this->carton, 12, '1000.00', true, $carton->id)]);

    expect(SkuUnit::query()->where('is_default', true)->pluck('id')->all())->toBe([$carton->id]);
});

test('D91 a new unit gets its tiers, an existing unit\'s price change is audited on the unit', function (): void {
    app(SaveSkuUnits::class)->handle($this->sku, [
        ($this->line)($this->piece, 1, '9.50', true, $this->defaultUnitId($this->sku)),
        ($this->line)($this->carton, 12, '100.00', tiers: [new TierData(5, '95.00')]),
    ], 'b6a4c1a0-0000-4000-8000-000000000001');

    expect(SkuUnit::query()->where('unit_id', $this->carton->id)->sole()->tiers->pluck('unit_price', 'min_quantity')->all())->toBe([5 => '95.00'])
        ->and(AuditLog::query()->get()->map->only(['action', 'entity_type', 'entity_id', 'batch_id'])->all())->toBe([
            ['action' => 'sku.price_changed', 'entity_type' => 'sku_units', 'entity_id' => $this->defaultUnitId($this->sku), 'batch_id' => 'b6a4c1a0-0000-4000-8000-000000000001'],
        ]);
});

test('D91 a pricing refusal names the unit it is about', function (Closure $line, string $code, array $details): void {
    expect(fn () => app(SaveSkuUnits::class)->handle($this->sku, [($this->line)($this->piece, 1, '10.00', true), $line($this->carton)]))
        ->toThrow(function (ApiException $e) use ($code, $details): void {
            expect([$e->status, $e->errorCode, $e->details])->toBe([422, $code, $details]);
        });
})->with([
    'invalid tiers' => [fn (Unit $carton): SkuUnitData => new SkuUnitData(null, $carton->id, 12, false, new SkuPricingData('100.00', tiers: [new TierData(5, '120.00')])), 'invalid_tiers', ['sku.units.1.tiers' => ['سعر شريحة 5 أعلى من السعر الأساسي']]],
    'a percent without a cover price' => [fn (Unit $carton): SkuUnitData => new SkuUnitData(null, $carton->id, 12, false, new SkuPricingData(null, '20.00')), 'cover_price_required', ['sku.units.1.cover_price' => ['أدخل سعر القطاعي أولًا لحساب السعر بنسبة الخصم']]],
]);
