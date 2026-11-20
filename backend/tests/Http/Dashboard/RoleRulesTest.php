<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Tenancy\Enums\TenantUserRole;

/*
| D82: the rules a route permission cannot express. The warehouse role has `orders.manage` but moves orders only to
| preparing / out for delivery and never cancels; collecting in full on delivery needs `collections.manage`; on a
| customer, the name and addresses need `customers.manage` and the credit terms `customers.credit`.
*/

beforeEach(function (): void {
    $this->customer = $this->makeCustomer(['name' => 'مكتبة الأمل']);
    $this->order = $this->placeOrder($this->customer, [$this->orderableSku()->id => 5]);
    $this->as = fn (TenantUserRole $role) => $this->actingAsOwner($this->makeOwner(attributes: ['role' => $role]));
    $this->move = fn (OrderStatus $status) => $this->inTenant(fn () => $this->order->forceFill(['status' => $status])->save());
    $this->transition = fn (array $body) => $this->tenantJson('POST', "/api/dashboard/orders/{$this->order->number}/transition", $body);
});

test('D82 the warehouse may not confirm or deliver an order', function (OrderStatus $from, string $to): void {
    ($this->as)(TenantUserRole::Warehouse);
    ($this->move)($from);

    ($this->transition)(['to' => $to])->assertForbidden()->assertJsonPath('error.code', 'forbidden');
})->with([
    'confirm' => [OrderStatus::Pending, 'confirmed'],
    'deliver' => [OrderStatus::OutForDelivery, 'delivered'],
]);

test('D82 the warehouse prepares an order and sends it out', function (): void {
    ($this->as)(TenantUserRole::Warehouse);
    ($this->move)(OrderStatus::Confirmed);

    ($this->transition)(['to' => 'preparing'])->assertOk()->assertJsonPath('data.status', 'preparing');
    ($this->transition)(['to' => 'out_for_delivery'])->assertOk()->assertJsonPath('data.status', 'out_for_delivery');
});

test('D82 the warehouse may not cancel an order; sales may', function (): void {
    ($this->as)(TenantUserRole::Warehouse);
    $this->tenantJson('POST', "/api/dashboard/orders/{$this->order->number}/cancel", ['reason' => 'نفاد'])->assertForbidden();

    ($this->as)(TenantUserRole::Sales);
    $this->tenantJson('POST', "/api/dashboard/orders/{$this->order->number}/cancel", ['reason' => 'نفاد'])->assertOk();
});

test('D82 delivering with the full amount collected needs collections.manage', function (): void {
    ($this->as)(TenantUserRole::Sales);
    ($this->move)(OrderStatus::OutForDelivery);

    ($this->transition)(['to' => 'delivered', 'collect_full_amount' => true])->assertForbidden();
    ($this->transition)(['to' => 'delivered'])->assertOk()->assertJsonPath('data.collection_status', 'unpaid');
});

test('D82 an accountant changes the credit terms but not the name', function (): void {
    ($this->as)(TenantUserRole::Accountant);
    $uri = "/api/dashboard/customers/{$this->customer->id}";

    $this->tenantJson('PUT', $uri, ['name' => 'مكتبة الأمل', 'credit_limit' => '5000', 'payment_terms_days' => 30])
        ->assertOk()
        ->assertJsonPath('data.credit_limit', '5000.00');
    $this->tenantJson('PUT', $uri, ['name' => 'اسم آخر'])->assertForbidden();
});

test('D82 sales edits the customer but not the credit terms', function (): void {
    ($this->as)(TenantUserRole::Sales);
    $uri = "/api/dashboard/customers/{$this->customer->id}";

    $this->tenantJson('PUT', $uri, ['name' => 'اسم آخر'])->assertOk()->assertJsonPath('data.name', 'اسم آخر');
    $this->tenantJson('PUT', $uri, ['name' => 'اسم آخر', 'credit_limit' => '100'])->assertForbidden();
    $this->tenantJson('PUT', $uri, ['name' => 'اسم آخر', 'credit_limit' => null])->assertOk();
});
