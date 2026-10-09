<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

test('BR-P04 allowed status changes succeed and are audited', function (ProductStatus $from, string $to): void {
    $product = $this->inTenant(fn () => Product::factory()->withSku()->create(['status' => $from]));

    $this->tenantJson('PATCH', "/api/dashboard/products/{$product->id}/status", ['status' => $to])
        ->assertOk()
        ->assertJsonPath('data.status', $to);

    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $this->tenant->id, 'action' => 'product.status_changed', 'entity_id' => $product->id, 'actor_id' => $this->me->id,
        'old_values' => json_encode(['status' => $from->value]), 'new_values' => json_encode(['status' => $to]),
    ]);
})->with([
    'active to inactive' => [ProductStatus::Active, 'inactive'],
    'inactive to active' => [ProductStatus::Inactive, 'active'],
    'coming soon to active' => [ProductStatus::ComingSoon, 'active'],
    'coming soon to inactive' => [ProductStatus::ComingSoon, 'inactive'],
]);

test('BR-P04 every other status change is a 409 invalid_transition and changes nothing', function (ProductStatus $from, string $to): void {
    $product = $this->inTenant(fn () => Product::factory()->withSku()->create(['status' => $from]));

    $this->tenantJson('PATCH', "/api/dashboard/products/{$product->id}/status", ['status' => $to])
        ->assertConflict()
        ->assertJsonPath('error.code', 'invalid_transition')
        ->assertJsonPath('error.details', ['from' => $from->value, 'to' => $to]);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => $from->value]);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'active to coming soon' => [ProductStatus::Active, 'coming_soon'],
    'inactive to coming soon' => [ProductStatus::Inactive, 'coming_soon'],
    'active to active' => [ProductStatus::Active, 'active'],
    'inactive to inactive' => [ProductStatus::Inactive, 'inactive'],
]);

test('the invalid transition message names both statuses in arabic', function (): void {
    $product = $this->inTenant(fn () => Product::factory()->withSku()->inactive()->create());

    $this->tenantJson('PATCH', "/api/dashboard/products/{$product->id}/status", ['status' => 'coming_soon'])
        ->assertJsonPath('error.message', 'لا يمكن تغيير حالة المنتج من «غير نشط» إلى «قريبًا»');
});

test('status validation', function (array $body): void {
    $product = $this->inTenant(fn () => Product::factory()->withSku()->create());

    $this->tenantJson('PATCH', "/api/dashboard/products/{$product->id}/status", $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['status']]]);
})->with([
    'missing' => [[]],
    'unknown' => [['status' => 'archived']],
]);

test('BR-T04 changing the status of a product of another tenant is 404 and untouched', function (): void {
    $foreign = $this->inTenant(fn () => Product::factory()->withSku()->create(), $this->otherTenant());

    $this->tenantJson('PATCH', "/api/dashboard/products/{$foreign->id}/status", ['status' => 'inactive'])
        ->assertNotFound();

    $this->assertDatabaseHas('products', ['id' => $foreign->id, 'status' => 'active']);
});
