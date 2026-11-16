<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Exceptions\CostingRefused;
use App\Modules\Inventory\Queries\SkuUnitFactorQuery;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->sku = Sku::factory()->for(Product::factory())->create();
});

test('D96 a the factor of a unit is its own, the base unit has 1, and a unit of another sku is refused', function (): void {
    $carton = $this->addUnit($this->sku, ['conversion_factor' => 24]);
    $other = Sku::factory()->for(Product::factory())->create();
    $query = app(SkuUnitFactorQuery::class);

    expect($query->get($this->sku, $carton->id))->toBe(24)
        ->and($query->get($this->sku, null))->toBe(1)
        ->and(fn (): int => $query->get($this->sku, $this->defaultUnitId($other)))->toThrow(function (CostingRefused $e): void {
            expect([$e->status, $e->errorCode, $e->details])->toBe([422, 'validation_failed', ['sku_unit_id' => ['الوحدة ليست من وحدات هذا الصنف']]]);
        });
});
