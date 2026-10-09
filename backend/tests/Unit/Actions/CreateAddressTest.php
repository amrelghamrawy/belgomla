<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\CreateAddress;
use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->create(['phone' => '+201012345678']);
});

test('BR-CU03 the first address is the default and takes the customer phone', function (): void {
    $address = app(CreateAddress::class)->handle($this->customer, new AddressData('المحل', 'القاهرة', 'نصر', 'شارع 1', 'بجوار البنك'));

    expect($address->only(['customer_id', 'label', 'governorate', 'city', 'address_line', 'landmark', 'phone', 'is_default']))->toBe([
        'customer_id' => $this->customer->id, 'label' => 'المحل', 'governorate' => 'القاهرة', 'city' => 'نصر',
        'address_line' => 'شارع 1', 'landmark' => 'بجوار البنك', 'phone' => '+201012345678', 'is_default' => true,
    ]);
});

test('BR-CU03 a later address is not the default unless asked', function (): void {
    CustomerAddress::factory()->for($this->customer)->default()->create();

    $address = app(CreateAddress::class)->handle($this->customer, new AddressData('المخزن', 'القاهرة', 'نصر', 'شارع 2', phone: '+201112345678'));

    expect($address->is_default)->toBeFalse()
        ->and($address->phone)->toBe('+201112345678');
});

test('BR-CU03 a later address asked to be the default takes it over', function (): void {
    $old = CustomerAddress::factory()->for($this->customer)->default()->create();

    $address = app(CreateAddress::class)->handle($this->customer, new AddressData('المخزن', 'القاهرة', 'نصر', 'شارع 2', isDefault: true));

    expect($address->is_default)->toBeTrue()
        ->and($old->refresh()->is_default)->toBeFalse();
});
