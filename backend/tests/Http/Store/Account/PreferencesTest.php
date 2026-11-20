<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;

beforeEach(function (): void {
    $this->customer = $this->actingAsCustomer();
    $this->patch = fn (array $body) => $this->tenantJson('PATCH', '/api/store/account/preferences', $body);
});

test('BR-N03 a customer turns order messages off and gets their profile back', function (): void {
    ($this->patch)(['order_notifications' => false])
        ->assertOk()
        ->assertJsonPath('data.id', $this->customer->id)
        ->assertJsonPath('data.order_notifications', false);

    $this->assertDatabaseHas('customers', ['id' => $this->customer->id, 'order_notifications_opt_out' => true]);
    expect(AuditLog::withoutTenantScope()->where('action', 'customer.preferences_changed')->sole()->only(['actor_id', 'old_values', 'new_values']))->toBe([
        'actor_id' => $this->customer->id, 'old_values' => ['order_notifications' => true], 'new_values' => ['order_notifications' => false],
    ]);
});

test('BR-N03 turning them back on, and sending the same value again writes no audit', function (): void {
    ($this->patch)(['order_notifications' => true])->assertOk()->assertJsonPath('data.order_notifications', true);

    expect(AuditLog::withoutTenantScope()->where('action', 'customer.preferences_changed')->count())->toBe(0);
});

test('the preference is validated', function (array $body): void {
    ($this->patch)($body)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['order_notifications']]]);
})->with(['missing' => [[]], 'not a boolean' => [['order_notifications' => 'maybe']]]);

test('a guest has no preferences', function (): void {
    auth('customer')->logout();

    $this->tenantJson('PATCH', '/api/store/account/preferences', ['order_notifications' => false])->assertUnauthorized();
});

test('Tech §6 a customer session of this store is refused on another store\'s host', function (): void {
    $this->tenantJson('PATCH', '/api/store/account/preferences', ['order_notifications' => false], tenant: $this->otherTenant())->assertUnauthorized();

    $this->assertDatabaseHas('customers', ['id' => $this->customer->id, 'order_notifications_opt_out' => false]);
});
