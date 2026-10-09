<?php

declare(strict_types=1);

use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Services\OrderSnapshots;
use App\Modules\Pricing\Data\ResolvedPrice;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->customer = $this->makeCustomer(['name' => 'مكتبة الأمل', 'phone' => '+201012345678']);
    $this->sku = $this->orderableSku(['code' => 'ADW-SCI', 'name' => 'علوم ترم أول']);
    app(TenantContext::class)->set($this->tenant);
});

test('BR-PR07 the item snapshot keeps names, unit, conversion and the applied price', function (): void {
    $sku = $this->sku->load(['product', 'sellingUnit']);
    $price = new ResolvedPrice(unitPrice: '85.00', tierMinQuantity: 10, discountPercent: '29.17', coverPrice: '120.00', lineTotal: '1020.00');

    expect(new OrderSnapshots()->item(new CheckoutLine($sku, 12, $price)))->toBe([
        'sku_id' => $sku->id,
        'product_id' => $sku->product_id,
        'product_name' => 'الأضواء علوم',
        'sku_code' => 'ADW-SCI',
        'sku_name' => 'علوم ترم أول',
        'unit_name' => 'كرتونة',
        'conversion_factor' => 20,
        'quantity' => 12,
        'quantity_base' => 240,
        'cover_price' => '120.00',
        'unit_price' => '85.00',
        'discount_percent' => '29.17',
        'tier_min_quantity' => 10,
        'line_total' => '1020.00',
    ]);
});

test('BR-PR07 a sku without its own name is snapshotted with the product name', function (): void {
    $this->sku->forceFill(['name' => null])->save();
    $price = new ResolvedPrice(unitPrice: '90.00', tierMinQuantity: null, discountPercent: null, coverPrice: null, lineTotal: '450.00');

    expect(new OrderSnapshots()->item(new CheckoutLine($this->sku->load(['product', 'sellingUnit']), 5, $price))['sku_name'])->toBe('الأضواء علوم');
});

test('BR-O10 the delivery snapshot takes the customer name and the address with its phone', function (): void {
    $address = CustomerAddress::factory()->for($this->customer)->create(['label' => 'المخزن', 'city' => 'الدقي', 'landmark' => 'أمام البنك', 'phone' => '+201198765432']);

    expect(new OrderSnapshots()->delivery($this->customer, $address))->toBe([
        'delivery_name' => 'مكتبة الأمل',
        'delivery_phone' => '+201198765432',
        'delivery_address' => ['label' => 'المخزن', 'governorate' => 'القاهرة', 'city' => 'الدقي', 'address_line' => '12 شارع عباس العقاد', 'landmark' => 'أمام البنك'],
    ]);
});

test('BR-O10 an address without a phone falls back to the customer phone', function (): void {
    $address = CustomerAddress::factory()->for($this->customer)->create(['phone' => null]);

    expect(new OrderSnapshots()->delivery($this->customer, $address)['delivery_phone'])->toBe('+201012345678');
});
