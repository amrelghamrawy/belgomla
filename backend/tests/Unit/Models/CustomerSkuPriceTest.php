<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerSkuPrice;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $price = CustomerSkuPrice::factory()->create()->fresh();

    expect(array_keys($price?->toArray() ?? []))->toBe(['id', 'tenant_id', 'customer_id', 'sku_id', 'unit_price', 'note', 'created_at', 'updated_at', 'sku_unit_id']);
});

test('casts: the unit price as a 2-decimal string', function (): void {
    expect(CustomerSkuPrice::factory()->create(['unit_price' => 82.5])->fresh()?->unit_price)->toBe('82.50');
});

test('relations: the customer and the sku, and the customer\'s prices', function (): void {
    $price = CustomerSkuPrice::factory()->create();

    expect($price->customer)->toBeInstanceOf(Customer::class)
        ->and($price->sku)->toBeInstanceOf(Sku::class)
        ->and($price->customer->skuPrices->modelKeys())->toBe([$price->id]);
});

test('D91 a negotiated price belongs to the SKU unit it is for', function (): void {
    $price = CustomerSkuPrice::factory()->create();

    expect($price->skuUnit->id)->toBe($price->sku_unit_id)
        ->and($price->skuUnit->sku_id)->toBe($price->sku_id);
});
