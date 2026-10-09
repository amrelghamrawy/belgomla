<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Actions\UnblockCustomer;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\InvalidCustomerTransition;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-CU05 unblocking restores the previous status and clears the block, audited', function (): void {
    $customer = Customer::factory()->blocked(CustomerStatus::PendingApproval)->create();

    app(UnblockCustomer::class)->handle($customer);

    $customer->refresh();
    expect([$customer->status, $customer->status_before_block, $customer->blocked_at, $customer->blocked_reason])->toBe([CustomerStatus::PendingApproval, null, null, null])
        ->and(AuditLog::query()->sole()->only(['action', 'new_values']))->toBe(['action' => 'customer.unblocked', 'new_values' => ['status' => 'pending_approval']]);
});

test('a blocked customer with no recorded previous status becomes active', function (): void {
    $customer = Customer::factory()->create(['status' => CustomerStatus::Blocked, 'status_before_block' => null]);

    expect(app(UnblockCustomer::class)->handle($customer)->status)->toBe(CustomerStatus::Active);
});

test('a customer who is not blocked cannot be unblocked', function (): void {
    app(UnblockCustomer::class)->handle(Customer::factory()->create());
})->throws(InvalidCustomerTransition::class);
