<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Orders\Models\MerchantNotification;

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
});

test('BR-N02 a new order and a customer cancellation reach the dashboard, newest first, with the unread count', function (): void {
    $customer = $this->makeCustomer(['name' => 'مكتبة الأمل']);
    $order = $this->placeOrder($customer, [$this->orderableSku()->id => 5]);
    $this->inTenant(fn () => app(App\Modules\Orders\Actions\CancelOrder::class)->handle($order, new App\Modules\Orders\Data\CancelData('طلبت بالخطأ'), App\Modules\Orders\Enums\CancelledBy::Customer, $customer->id));

    $response = $this->tenantJson('GET', '/api/dashboard/notifications')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'customer_cancelled')
        ->assertJsonPath('data.0.details.reason', 'طلبت بالخطأ')
        ->assertJsonPath('data.1.type', 'new_order')
        ->assertJsonPath('unread_count', 2)
        ->assertJsonPath('meta.total', 2)
        ->assertExactJsonStructure(['data' => ['*' => ['id', 'type', 'type_label', 'details', 'read_at', 'created_at']], 'links', 'meta', 'unread_count']);

    expect($response->json('data.1.details'))->toEqual(['order_number' => $order->number, 'customer_name' => 'مكتبة الأمل', 'order_total' => '450.00']);
});

test('BR-N02 ?unread=1 leaves out the read ones', function (): void {
    [$read, $unread] = $this->inTenant(fn (): array => [MerchantNotification::factory()->read()->create(), MerchantNotification::factory()->create()]);

    $this->tenantJson('GET', '/api/dashboard/notifications?unread=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $unread->id);
});

test('the list validates its filters', function (): void {
    $this->tenantJson('GET', '/api/dashboard/notifications?unread=maybe')->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['unread']]]);
});

test('BR-T03 another tenant\'s notifications are never listed', function (): void {
    $this->inTenant(fn () => MerchantNotification::factory()->create(), $this->otherTenant());

    $this->tenantJson('GET', '/api/dashboard/notifications')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
});

test('BR-N02 the merchant marks some notifications read, audited', function (): void {
    [$first, $second] = $this->inTenant(fn (): array => MerchantNotification::factory()->count(2)->create()->all());

    $this->tenantJson('POST', '/api/dashboard/notifications/read', ['ids' => [$first->id]])
        ->assertOk()
        ->assertExactJson(['data' => ['marked' => 1]]);

    $this->assertDatabaseMissing('notifications', ['id' => $first->id, 'read_at' => null]);
    $this->assertDatabaseHas('notifications', ['id' => $second->id, 'read_at' => null]);
    expect(AuditLog::withoutTenantScope()->where('action', 'notifications.read')->sole()->actor_id)->toBe($this->owner->id);
});

test('BR-N02 the merchant marks all notifications read', function (): void {
    $this->inTenant(fn () => MerchantNotification::factory()->count(3)->create());

    $this->tenantJson('POST', '/api/dashboard/notifications/read', ['all' => true])->assertOk()->assertJsonPath('data.marked', 3);

    $this->assertDatabaseMissing('notifications', ['read_at' => null]);
});

test('mark read validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/notifications/read', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'nothing sent' => [[], 'ids'],
    'empty ids' => [['ids' => []], 'ids'],
    'ids not numbers' => [['ids' => ['x']], 'ids.0'],
    'both ids and all' => [['ids' => [1], 'all' => true], 'all'],
    'all not accepted' => [['all' => false], 'all'],
]);

test('BR-T04 ids of another tenant are not marked there', function (): void {
    $theirs = $this->inTenant(fn () => MerchantNotification::factory()->create(), $this->otherTenant());

    $this->tenantJson('POST', '/api/dashboard/notifications/read', ['ids' => [$theirs->id]])->assertOk()->assertJsonPath('data.marked', 0);

    $this->assertDatabaseHas('notifications', ['id' => $theirs->id, 'read_at' => null]);
});
