<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\SetDefaultAddress;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-CU03 the previous default is unset before the new one is set', function (): void {
    $customer = Customer::factory()->create();
    $old = CustomerAddress::factory()->for($customer)->default()->create();
    $new = CustomerAddress::factory()->for($customer)->create();

    app(SetDefaultAddress::class)->handle($new);

    expect($new->refresh()->is_default)->toBeTrue()
        ->and($old->refresh()->is_default)->toBeFalse();
});

test('another customer\'s default is untouched', function (): void {
    $theirs = CustomerAddress::factory()->default()->create();

    app(SetDefaultAddress::class)->handle(CustomerAddress::factory()->create());

    expect($theirs->refresh()->is_default)->toBeTrue();
});
