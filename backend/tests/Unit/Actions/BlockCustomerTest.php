<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Actions\BlockCustomer;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\InvalidCustomerTransition;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-CU05 blocking keeps the previous status, the reason and the time, audited', function (): void {
    $this->freezeTime();
    $customer = Customer::factory()->invited()->create();

    app(BlockCustomer::class)->handle($customer, 'لم يسدد');

    $customer->refresh();
    expect([$customer->status, $customer->status_before_block, $customer->blocked_reason, $customer->blocked_at?->toIso8601String()])
        ->toBe([CustomerStatus::Blocked, CustomerStatus::Invited, 'لم يسدد', now()->toIso8601String()])
        ->and(AuditLog::query()->sole()->action)->toBe('customer.blocked');
});

test('blocking a blocked customer is refused', function (): void {
    app(BlockCustomer::class)->handle(Customer::factory()->blocked()->create(), 'مرة أخرى');
})->throws(InvalidCustomerTransition::class);
