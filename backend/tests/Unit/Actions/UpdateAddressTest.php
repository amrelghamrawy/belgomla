<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\UpdateAddress;
use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->create();
});

test('BR-CU03 every field is replaced; without a phone the stored one stays', function (): void {
    $address = CustomerAddress::factory()->for($this->customer)->default()->create(['phone' => '+201112345678', 'landmark' => 'قديم']);

    app(UpdateAddress::class)->handle($address, new AddressData('جديد', 'الجيزة', 'الدقي', 'شارع 9'));

    expect($address->refresh()->only(['label', 'governorate', 'city', 'address_line', 'landmark', 'phone', 'is_default']))->toBe([
        'label' => 'جديد', 'governorate' => 'الجيزة', 'city' => 'الدقي', 'address_line' => 'شارع 9', 'landmark' => null,
        'phone' => '+201112345678', 'is_default' => true,
    ]);
});

test('BR-CU03 is_default moves the default to this address', function (): void {
    $default = CustomerAddress::factory()->for($this->customer)->default()->create();
    $address = CustomerAddress::factory()->for($this->customer)->create();

    app(UpdateAddress::class)->handle($address, new AddressData('x', 'x', 'x', 'x', isDefault: true));

    expect($address->refresh()->is_default)->toBeTrue()
        ->and($default->refresh()->is_default)->toBeFalse();
});
