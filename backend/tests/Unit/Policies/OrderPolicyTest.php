<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Policies\OrderPolicy;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->create();
});

test('BR-CU02 a customer may view and cancel their own order, whatever its status', function (string $ability): void {
    $order = Order::factory()->for($this->customer)->delivered()->create();

    expect(Gate::forUser($this->customer)->inspect($ability, $order)->allowed())->toBeTrue();
})->with(['view', 'cancel']);

test('D57 someone else\'s order is reported as not found', function (string $ability): void {
    $response = Gate::forUser($this->customer)->inspect($ability, Order::factory()->create());

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
})->with(['view', 'cancel']);

test('the policy is registered for orders', function (): void {
    expect(Gate::getPolicyFor(Order::class))->toBeInstanceOf(OrderPolicy::class);
});
