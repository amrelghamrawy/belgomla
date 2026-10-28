<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Enums\TenantUserRole;
use Illuminate\Support\Facades\Queue;

/*
| BR-O14, D88 on the dashboard (08-api-contract.md §9): the pre-order list, a product's demand, converting its
| pre-orders once it is active and stocked, and the product form's pre-order settings. The SKU is a Coming Soon book
| with pre-orders on, cartons of 20, MOQ 5, 90.00 and 85.00 from 10 cartons.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->owner = $this->actingAsOwner();
    $this->sku = $this->preorderableSku(limit: 100);
    $this->product = $this->inTenant(fn (): Product => Product::query()->findOrFail($this->sku->product_id));
    $this->first = $this->placePreorder($this->makeCustomer(['name' => 'الأول']), [$this->sku->id => 10]);
    $this->travel(1)->minutes();
    $this->second = $this->placePreorder($this->makeCustomer(['name' => 'الثاني']), [$this->sku->id => 10]);
    $this->activate = function (int $cartons): void {
        $this->inTenant(function () use ($cartons): void {
            Product::query()->whereKey($this->product->id)->update(['status' => ProductStatus::Active->value]);
            SkuInventory::query()->whereKey($this->sku->id)->update(['on_hand' => $cartons * 20]);
        });
    };
    $this->convert = fn (?int $productId = null, array $headers = []) => $this->tenantJson('POST', '/api/dashboard/products/'.($productId ?? $this->product->id).'/convert-preorders', headers: $headers);
});

test('BR-O14 the merchant lists the pre-orders oldest first with their lines', function (): void {
    $this->placeOrder($this->makeCustomer(), [$this->orderableSku()->id => 5]);

    $response = $this->tenantJson('GET', "/api/dashboard/preorders?product_id={$this->product->id}&status=open")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.number', $this->first->number)
        ->assertJsonPath('data.1.number', $this->second->number)
        ->assertJsonPath('data.0.items.0.quantity', 10)
        ->assertJsonPath('data.0.customer.name', 'الأول');

    expectDocumentedShape($response->json('data.0'), 'DashboardPreorder');
});

test('BR-O14 the list validates its filters', function (string $query, string $field): void {
    $this->tenantJson('GET', "/api/dashboard/preorders?{$query}")->assertUnprocessable()->assertJsonValidationErrorFor($field, 'error.details');
})->with([
    'unknown state' => ['status=shipped', 'status'],
    'bad product id' => ['product_id=x', 'product_id'],
]);

test('BR-O14 the demand of a product: quantity, customers, pre-orders and what remains under the cap', function (): void {
    $response = $this->tenantJson('GET', "/api/dashboard/products/{$this->product->id}/preorder-demand")
        ->assertOk()
        ->assertExactJson(['data' => [
            'product_id' => $this->product->id, 'status' => 'coming_soon', 'preorder_enabled' => true, 'expected_at' => '2026-12-01',
            'limit' => 100, 'remaining' => 80, 'quantity' => 20, 'quantity_base' => 400, 'customers' => 2, 'preorders' => 2,
        ]]);

    expectDocumentedShape($response->json('data'), 'PreorderDemand');
});

test('BR-O14 going active answers the product with its pre-order demand; nothing is converted by itself', function (): void {
    $this->tenantJson('PATCH', "/api/dashboard/products/{$this->product->id}/status", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('meta.preorder_demand', ['quantity' => 20, 'quantity_base' => 400, 'customers' => 2, 'preorders' => 2]);

    $this->assertDatabaseMissing('orders', ['kind' => 'order']);
});

test('BR-O14 converting before the product is active answers 409 product_not_active', function (): void {
    ($this->convert)()->assertConflict()->assertJsonPath('error.code', 'product_not_active')->assertJsonPath('error.details.status', 'coming_soon');
});

test('D88 converting answers the summary: the oldest in full, the next in part', function (): void {
    ($this->activate)(15);

    $response = ($this->convert)()->assertOk()
        ->assertJsonPath('data.converted_orders.0.preorder_number', $this->first->number)
        ->assertJsonPath('data.converted_orders.0.quantity', 10)
        ->assertJsonPath('data.converted_orders.0.order_total', '850.00')
        ->assertJsonPath('data.converted_orders.1.preorder_number', $this->second->number)
        ->assertJsonPath('data.converted_orders.1.quantity', 5)
        ->assertJsonPath('data.partially', 1)
        ->assertJsonPath('data.untouched', 0);

    expectDocumentedShape($response->json('data'), 'PreorderConversion');
    $this->tenantJson('GET', "/api/dashboard/orders/{$this->second->number}")
        ->assertOk()
        ->assertJsonPath('data.preorder_state', 'open')
        ->assertJsonPath('data.items.0.converted_quantity', 5)
        ->assertJsonPath('data.changes.0.change', 'converted')
        ->assertJsonPath('data.conversions.0.number', $response->json('data.converted_orders.1.number'));
});

test('D55 the same Idempotency-Key twice converts once and replays the summary', function (): void {
    ($this->activate)(15);

    ($this->convert)(headers: ['Idempotency-Key' => 'conv-1'])->assertOk();
    ($this->convert)(headers: ['Idempotency-Key' => 'conv-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect(Order::withoutTenantScope()->where('kind', 'order')->count())->toBe(2);
});

test('BR-L each conversion is audited by the user', function (): void {
    ($this->activate)(10);

    ($this->convert)()->assertOk();

    expect(AuditLog::withoutTenantScope()->where('action', 'order.preorder_converted')->sole()->actor_id)->toBe($this->owner->id);
});

test('D82 an accountant lists and reads the demand but cannot convert', function (): void {
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => TenantUserRole::Accountant]));
    ($this->activate)(15);

    $this->tenantJson('GET', '/api/dashboard/preorders')->assertOk();
    ($this->convert)()->assertForbidden();
});

test('BR-T04 another store\'s product is 404 for the demand and the conversion, and nothing changes there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->preorderableSku(tenant: $other);
    $preorder = $this->placePreorder($this->makeCustomer(tenant: $other), [$theirs->id => 5]);

    $this->tenantJson('GET', "/api/dashboard/products/{$theirs->product_id}/preorder-demand")->assertNotFound();
    ($this->convert)($theirs->product_id)->assertNotFound();

    $this->assertDatabaseHas('orders', ['id' => $preorder->id, 'converted_at' => null]);
});

test('BR-O14 a pre-order cannot be confirmed from the dashboard: 409 invalid_transition', function (): void {
    $this->tenantJson('POST', "/api/dashboard/orders/{$this->first->number}/transition", ['to' => 'confirmed'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'invalid_transition');

    $this->assertDatabaseHas('orders', ['id' => $this->first->id, 'status' => 'pending']);
});

test('D88 the merchant cancels a pre-order', function (): void {
    $this->tenantJson('POST', "/api/dashboard/orders/{$this->first->number}/cancel", ['reason' => 'توقف الطبع'])
        ->assertOk()
        ->assertJsonPath('data.preorder_state', 'cancelled');
});

test('BR-O14 the orders list marks pre-orders and filters them', function (): void {
    $this->tenantJson('GET', '/api/dashboard/orders?kind=preorder')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.kind', 'preorder')
        ->assertJsonPath('data.0.is_stale', false);
});
