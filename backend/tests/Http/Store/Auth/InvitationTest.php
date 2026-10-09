<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Tenancy\Models\TenantSetting;

beforeEach(function (): void {
    $this->freezeTime();
    $this->token = str_repeat('a', 40);
    $this->customer = $this->makeCustomer(['name' => 'مكتبة الأمل', 'phone' => '+201012345678', 'status' => CustomerStatus::Invited, 'password' => null, 'phone_verified_at' => null]);
    $this->invitation = $this->inTenant(fn () => CustomerInvitation::factory()->token($this->token)->create(['customer_id' => $this->customer->id]));
});

test('BR-CU08 the invitation page shows the name, the masked phone and the expiry', function (): void {
    $this->tenantJson('GET', "/api/store/auth/invitation/{$this->token}")
        ->assertOk()
        ->assertExactJson(['data' => [
            'name' => 'مكتبة الأمل',
            'phone_masked' => '+20101****678',
            'expires_at' => now()->addDays(7)->toIso8601String(),
        ]]);
});

test('BR-CU08 setting a password activates the customer, verifies the phone and starts a session', function (): void {
    $this->tenantJson('POST', "/api/store/auth/invitation/{$this->token}", ['password' => 'secret123'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.phone_verified_at', now()->toIso8601String());

    $this->tenantJson('GET', '/api/store/me')->assertOk()->assertJsonPath('data.id', $this->customer->id);
    expect($this->inTenant(fn () => CustomerInvitation::query()->sole()->used_at))->not->toBeNull();
});

test('D2 with approval required an activated invitation waits for the merchant', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['require_customer_approval' => true]);

    $this->tenantJson('POST', "/api/store/auth/invitation/{$this->token}", ['password' => 'secret123'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_approval');
});

test('BR-CU08 an invitation link works once', function (): void {
    $this->tenantJson('POST', "/api/store/auth/invitation/{$this->token}", ['password' => 'secret123'])->assertOk();

    $this->tenantJson('GET', "/api/store/auth/invitation/{$this->token}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
    $this->tenantJson('POST', "/api/store/auth/invitation/{$this->token}", ['password' => 'another1'])->assertNotFound();
});

test('BR-CU08 a link is valid 7 days', function (): void {
    $this->travel(7)->days();
    $this->travel(1)->seconds();

    $this->tenantJson('GET', "/api/store/auth/invitation/{$this->token}")->assertNotFound();
    $this->tenantJson('POST', "/api/store/auth/invitation/{$this->token}", ['password' => 'secret123'])->assertNotFound();
});

test('an unknown token is 404', function (): void {
    $this->tenantJson('GET', '/api/store/auth/invitation/'.str_repeat('b', 40))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

test('a malformed token never reaches the controller', function (): void {
    $this->tenantJson('GET', '/api/store/auth/invitation/short')->assertNotFound();
});

test('BR-CU05 the link of a customer who was blocked meanwhile is dead', function (): void {
    Customer::withoutTenantScope()->update(['status' => CustomerStatus::Blocked]);

    $this->tenantJson('POST', "/api/store/auth/invitation/{$this->token}", ['password' => 'secret123'])->assertNotFound();
});

test('BR-T04 a link of another tenant is 404 on this host and stays usable there', function (): void {
    $other = $this->otherTenant();
    $foreignToken = str_repeat('c', 40);
    $this->inTenant(fn () => CustomerInvitation::factory()->token($foreignToken)->create(), $other);

    $this->tenantJson('POST', "/api/store/auth/invitation/{$foreignToken}", ['password' => 'secret123'])->assertNotFound();

    $this->tenantJson('GET', "/api/store/auth/invitation/{$foreignToken}", tenant: $other)->assertOk();
});

test('activation validation', function (array $body): void {
    $this->tenantJson('POST', "/api/store/auth/invitation/{$this->token}", $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['password']]]);
})->with([
    'missing password' => [[]],
    'D50 short password' => [['password' => '1234567']],
]);
