<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\Queue;

/*
| End-to-end customer journeys over HTTP (tasks/5-customers.md §3), with the OTP and the invitation link read
| from the queued message the way a customer would read them on their phone.
*/

beforeEach(function (): void {
    Queue::fake();
});

test('BR-CU04 register → otp → active → logout → login', function (): void {
    $this->tenantJson('POST', '/api/store/auth/register', ['name' => 'محمود', 'phone' => '01012345678', 'password' => 'secret123'])->assertOk();

    $this->tenantJson('POST', '/api/store/auth/verify-otp', ['phone' => '01012345678', 'code' => lastOtpCode(), 'purpose' => 'register'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
    $this->tenantJson('POST', '/api/store/auth/logout')->assertNoContent();

    $this->tenantJson('POST', '/api/store/auth/login', ['phone' => '01012345678', 'password' => 'secret123'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

test('D2 register with approval on → pending_approval → login 403 → merchant approves → login ok', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['require_customer_approval' => true]);
    $this->tenantJson('POST', '/api/store/auth/register', ['name' => 'محمود', 'phone' => '01012345678', 'password' => 'secret123'])->assertOk();
    $this->tenantJson('POST', '/api/store/auth/verify-otp', ['phone' => '01012345678', 'code' => lastOtpCode(), 'purpose' => 'register'])
        ->assertJsonPath('data.status', 'pending_approval');
    $this->tenantJson('POST', '/api/store/auth/logout')->assertNoContent();

    $this->tenantJson('POST', '/api/store/auth/login', ['phone' => '01012345678', 'password' => 'secret123'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'pending_approval');

    $this->actingAsOwner();
    $customerId = Customer::withoutTenantScope()->sole()->id;
    $this->tenantJson('POST', "/api/dashboard/customers/{$customerId}/approve")->assertOk()->assertJsonPath('data.status', 'active');

    $this->tenantJson('POST', '/api/store/auth/login', ['phone' => '01012345678', 'password' => 'secret123'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

test('BR-CU08 merchant creates → invites → customer opens the link → sets a password → logs in', function (): void {
    $this->actingAsOwner();
    $id = $this->tenantJson('POST', '/api/dashboard/customers', ['name' => 'مكتبة الأمل', 'phone' => '01012345678'])
        ->assertCreated()
        ->json('data.id');
    $this->tenantJson('POST', "/api/dashboard/customers/{$id}/invite")->assertOk();
    $token = lastInvitationToken();

    expect(lastMessageText())->toContain("https://alnoor.gomal.test/account/invite/{$token}");
    $this->tenantJson('GET', "/api/store/auth/invitation/{$token}")->assertOk()->assertJsonPath('data.name', 'مكتبة الأمل');
    $this->tenantJson('POST', "/api/store/auth/invitation/{$token}", ['password' => 'secret123'])->assertOk()->assertJsonPath('data.status', 'active');
    $this->tenantJson('POST', '/api/store/auth/logout')->assertNoContent();

    $this->tenantJson('POST', '/api/store/auth/login', ['phone' => '01012345678', 'password' => 'secret123'])->assertOk();
});

test('BR-CU08 an invited phone that tries to register is told to use the invitation, and the link still works', function (): void {
    $this->actingAsOwner();
    $id = $this->tenantJson('POST', '/api/dashboard/customers', ['name' => 'مكتبة الأمل', 'phone' => '01012345678'])->json('data.id');
    $this->tenantJson('POST', "/api/dashboard/customers/{$id}/invite")->assertOk();

    $this->tenantJson('POST', '/api/store/auth/register', ['name' => 'أي اسم', 'phone' => '01012345678', 'password' => 'secret123'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'use_invitation');

    $this->tenantJson('POST', '/api/store/auth/invitation/'.lastInvitationToken(), ['password' => 'secret123'])->assertOk();
});
