<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Enums\CustomerStatus;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

test('D2 approving a pending customer activates them, records who approved, audited', function (): void {
    $customer = $this->makeCustomer(['status' => CustomerStatus::PendingApproval]);

    $this->tenantJson('POST', "/api/dashboard/customers/{$customer->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.approved_by', $this->me->id);

    expect($this->inTenant(fn () => AuditLog::query()->sole()->only(['action', 'entity_id', 'actor_id', 'old_values', 'new_values'])))
        ->toBe(['action' => 'customer.approved', 'entity_id' => $customer->id, 'actor_id' => $this->me->id, 'old_values' => ['status' => 'pending_approval'], 'new_values' => ['status' => 'active']]);
});

test('BR-CU05 only a pending-approval customer can be approved', function (CustomerStatus $status): void {
    $customer = $this->makeCustomer(['status' => $status]);

    $this->tenantJson('POST', "/api/dashboard/customers/{$customer->id}/approve")
        ->assertConflict()
        ->assertJsonPath('error.code', 'invalid_transition')
        ->assertJsonPath('error.details.from', $status->value);

    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => $status->value]);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'active' => [CustomerStatus::Active],
    'invited' => [CustomerStatus::Invited],
    'pending verification' => [CustomerStatus::PendingVerification],
    'blocked' => [CustomerStatus::Blocked],
]);

test('BR-CU05 blocking keeps the reason and the previous status, audited', function (): void {
    $this->freezeTime();
    $customer = $this->makeCustomer(['status' => CustomerStatus::PendingApproval]);

    $this->tenantJson('POST', "/api/dashboard/customers/{$customer->id}/block", ['reason' => 'لم يسدد'])
        ->assertOk()
        ->assertJsonPath('data.status', 'blocked')
        ->assertJsonPath('data.blocked_reason', 'لم يسدد')
        ->assertJsonPath('data.blocked_at', now()->toIso8601String());

    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status_before_block' => 'pending_approval']);
    expect($this->inTenant(fn () => AuditLog::query()->sole()->only(['action', 'old_values', 'new_values'])))
        ->toEqual(['action' => 'customer.blocked', 'old_values' => ['status' => 'pending_approval'], 'new_values' => ['status' => 'blocked', 'reason' => 'لم يسدد']]);
});

test('BR-CU05 a blocked customer cannot log in', function (): void {
    $customer = $this->makeCustomer(['phone' => '+201012345678', 'password' => 'secret123']);
    $this->tenantJson('POST', "/api/dashboard/customers/{$customer->id}/block", ['reason' => 'x'])->assertOk();

    $this->tenantJson('POST', '/api/store/auth/login', ['phone' => '01012345678', 'password' => 'secret123'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'blocked');
});

test('BR-CU05 blocking twice is a conflict', function (): void {
    $customer = $this->makeCustomer(['status' => CustomerStatus::Blocked, 'status_before_block' => CustomerStatus::Active]);

    $this->tenantJson('POST', "/api/dashboard/customers/{$customer->id}/block", ['reason' => 'x'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'invalid_transition');
});

test('block validation', function (array $body): void {
    $customer = $this->makeCustomer();

    $this->tenantJson('POST', "/api/dashboard/customers/{$customer->id}/block", $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['reason']]]);
})->with(['missing reason' => [[]], 'reason too long' => [['reason' => str_repeat('س', 301)]]]);

test('BR-CU05 unblocking restores the status before the block, audited', function (CustomerStatus $before): void {
    $customer = $this->makeCustomer(['status' => CustomerStatus::Blocked, 'status_before_block' => $before, 'blocked_reason' => 'x', 'blocked_at' => now()]);

    $this->tenantJson('POST', "/api/dashboard/customers/{$customer->id}/unblock")
        ->assertOk()
        ->assertJsonPath('data.status', $before->value)
        ->assertJsonPath('data.blocked_at', null)
        ->assertJsonPath('data.blocked_reason', null);

    expect($this->inTenant(fn () => AuditLog::query()->sole()->only(['action', 'old_values', 'new_values'])))
        ->toBe(['action' => 'customer.unblocked', 'old_values' => ['status' => 'blocked'], 'new_values' => ['status' => $before->value]]);
})->with([
    'active' => [CustomerStatus::Active],
    'invited' => [CustomerStatus::Invited],
    'pending approval' => [CustomerStatus::PendingApproval],
]);

test('BR-CU05 a customer who is not blocked cannot be unblocked', function (): void {
    $customer = $this->makeCustomer();

    $this->tenantJson('POST', "/api/dashboard/customers/{$customer->id}/unblock")
        ->assertConflict()
        ->assertJsonPath('error.code', 'invalid_transition')
        ->assertJsonPath('error.details.from', 'active');
});

test('the state machine error does not leak exception details', function (): void {
    config(['app.debug' => false]);
    $customer = $this->makeCustomer();

    $this->tenantJson('POST', "/api/dashboard/customers/{$customer->id}/approve")
        ->assertConflict()
        ->assertExactJson(['error' => [
            'code' => 'invalid_transition',
            'message' => 'لا يمكن تغيير حالة العميل من «نشط» إلى «نشط»',
            'details' => ['from' => 'active', 'to' => 'active'],
        ]]);
});
