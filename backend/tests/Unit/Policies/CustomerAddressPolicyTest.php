<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Policies\CustomerAddressPolicy;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->create();
});

test('BR-CU03 the owner may update and delete an active address', function (string $ability): void {
    $address = CustomerAddress::factory()->for($this->customer)->create();

    expect(Gate::forUser($this->customer)->inspect($ability, $address)->allowed())->toBeTrue();
})->with(['update', 'delete']);

test('BR-T04 someone else\'s address is reported as not found', function (string $ability): void {
    $response = Gate::forUser($this->customer)->inspect($ability, CustomerAddress::factory()->create());

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
})->with(['update', 'delete']);

test('BR-CU03 an archived address is reported as not found to its owner', function (string $ability): void {
    $response = Gate::forUser($this->customer)->inspect($ability, CustomerAddress::factory()->for($this->customer)->archived()->create());

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
})->with(['update', 'delete']);

test('the policy is registered for addresses', function (): void {
    expect(Gate::getPolicyFor(CustomerAddress::class))->toBeInstanceOf(CustomerAddressPolicy::class);
});
