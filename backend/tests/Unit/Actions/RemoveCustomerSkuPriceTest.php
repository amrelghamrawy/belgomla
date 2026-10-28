<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Actions\RemoveCustomerSkuPrice;
use App\Modules\Customers\Exceptions\CustomerPricingRefused;
use App\Modules\Customers\Models\CustomerSkuPrice;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = $this->makeCustomer();
    $this->sku = Product::factory()->withSku()->create()->sku()->sole();
    $this->unit = App\Modules\Catalog\Models\SkuUnit::query()->findOrFail($this->defaultUnitId($this->sku));
});

test('BR-PR13 removing a negotiated price brings back the normal price, audited with the old one', function (): void {
    CustomerSkuPrice::factory()->create(['customer_id' => $this->customer->id, 'sku_id' => $this->sku->id, 'unit_price' => '70.00', 'note' => 'قديم']);

    app(RemoveCustomerSkuPrice::class)->handle($this->customer, $this->unit);

    expect(CustomerSkuPrice::query()->count())->toBe(0)
        ->and(AuditLog::query()->sole()->only(['action', 'entity_id', 'old_values', 'new_values']))->toEqual([
            'action' => 'customer.price_removed', 'entity_id' => $this->customer->id,
            'old_values' => ['sku_id' => $this->sku->id, 'sku_unit_id' => $this->unit->id, 'unit_price' => '70.00', 'note' => 'قديم'], 'new_values' => null,
        ]);
});

test('BR-PR13 removing a price that does not exist is 404', function (): void {
    CustomerSkuPrice::factory()->create(['sku_id' => $this->sku->id]);

    expect(fn () => app(RemoveCustomerSkuPrice::class)->handle($this->customer, $this->unit))
        ->toThrow(function (CustomerPricingRefused $e): void {
            expect($e->status)->toBe(404)->and($e->errorCode)->toBe('not_found');
        })
        ->and(CustomerSkuPrice::query()->count())->toBe(1);
});

test('D91 removing the price of one unit keeps the price of the other', function (): void {
    $box = $this->addUnit($this->sku);
    CustomerSkuPrice::factory()->create(['customer_id' => $this->customer->id, 'sku_id' => $this->sku->id, 'unit_price' => '70.00']);
    CustomerSkuPrice::factory()->create(['customer_id' => $this->customer->id, 'sku_id' => $this->sku->id, 'sku_unit_id' => $box->id, 'unit_price' => '900.00']);

    app(RemoveCustomerSkuPrice::class)->handle($this->customer, $box);

    expect(CustomerSkuPrice::query()->sole()->sku_unit_id)->toBe($this->unit->id);
});
