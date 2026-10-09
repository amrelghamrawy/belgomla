<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\Sku;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

test('BR-P11 duplicating copies fields, units, prices and moq into a new inactive product with a new code', function (): void {
    $source = $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload(['sku' => ['base_price_percent' => 25]]))->json('data');

    $copy = $this->tenantJson('POST', "/api/dashboard/products/{$source['id']}/duplicate")
        ->assertCreated()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.name', $source['name'])
        ->assertJsonPath('data.category.id', $source['category']['id'])
        ->assertJsonPath('data.description', $source['description'])
        ->assertJsonPath('data.sku.code', 'ADW-SCI-P1-T1-COPY')
        ->assertJsonPath('data.sku.selling_unit', $source['sku']['selling_unit'])
        ->assertJsonPath('data.sku.base_unit', $source['sku']['base_unit'])
        ->assertJsonPath('data.sku.conversion_factor', 20)
        ->assertJsonPath('data.sku.cover_price', '120.00')
        ->assertJsonPath('data.sku.base_price', '90.00')
        ->assertJsonPath('data.sku.base_price_percent', '25.00')
        ->assertJsonPath('data.sku.moq', 5)
        ->json('data');

    expect($copy['id'])->not->toBe($source['id'])
        ->and($copy['slug'])->toBe($source['slug'].'-2');
    $this->assertDatabaseHas('products', ['id' => $copy['id'], 'created_by' => $this->me->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'product.duplicated', 'entity_id' => $copy['id'], 'actor_id' => $this->me->id]);
});

test('D30 the copy starts with an empty stock row and no movement, the source stock is untouched', function (): void {
    $source = $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload(['opening_stock' => 40]))->json('data');

    $this->tenantJson('POST', "/api/dashboard/products/{$source['id']}/duplicate")
        ->assertCreated()
        ->assertJsonPath('data.sku.inventory', ['on_hand' => 0, 'reserved' => 0, 'available' => 0]);

    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $source['sku']['id'], 'on_hand' => 40]);
    $this->assertDatabaseCount('inventory_movements', 1);
});

test('BR-P11 duplicating twice gives each copy its own code', function (): void {
    $id = $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload())->json('data.id');

    $this->tenantJson('POST', "/api/dashboard/products/{$id}/duplicate")->assertJsonPath('data.sku.code', 'ADW-SCI-P1-T1-COPY');
    $this->tenantJson('POST', "/api/dashboard/products/{$id}/duplicate")->assertJsonPath('data.sku.code', 'ADW-SCI-P1-T1-COPY-2');
});

test('BR-P11 custom fields are copied, images are not', function (): void {
    $source = $this->inTenant(function (): Product {
        $product = Product::factory()->withSku()->create(['custom_fields' => ['brand' => 'Bic']]);
        ProductImage::factory()->for($product)->main()->create();

        return $product;
    });

    $this->tenantJson('POST', "/api/dashboard/products/{$source->id}/duplicate")
        ->assertCreated()
        ->assertJsonPath('data.custom_fields', ['brand' => 'Bic'])
        ->assertJsonPath('data.images', [])
        ->assertJsonPath('data.main_image_url', null);
});

test('BR-T04 duplicating a product of another tenant is 404 and creates nothing', function (): void {
    $foreign = $this->inTenant(fn () => Product::factory()->withSku()->create(), $this->otherTenant());

    $this->tenantJson('POST', "/api/dashboard/products/{$foreign->id}/duplicate")->assertNotFound();

    expect(Product::withoutTenantScope()->count())->toBe(1)
        ->and(Sku::withoutTenantScope()->count())->toBe(1);
});
