<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Actions\ApproveCustomer;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\InvalidCustomerTransition;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('D2 a pending customer becomes active with the approver recorded, audited', function (): void {
    $owner = $this->makeOwner();
    $customer = Customer::factory()->pendingApproval()->create();

    app(ApproveCustomer::class)->handle($customer, $owner->id);

    expect($customer->refresh()->only(['status', 'approved_by']))->toBe(['status' => CustomerStatus::Active, 'approved_by' => $owner->id])
        ->and(AuditLog::query()->sole()->only(['action', 'old_values', 'new_values']))->toBe(['action' => 'customer.approved', 'old_values' => ['status' => 'pending_approval'], 'new_values' => ['status' => 'active']]);
});

test('BR-CU05 any other status is refused without an audit record', function (string $state): void {
    $customer = Customer::factory()->{$state}()->create();

    expect(fn () => app(ApproveCustomer::class)->handle($customer))->toThrow(InvalidCustomerTransition::class);

    expect(AuditLog::query()->count())->toBe(0);
})->with(['invited', 'pendingVerification', 'blocked']);
