<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductRelation;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
    [$this->book, $this->revision] = $this->inTenant(fn (): array => [
        Product::factory()->create(['name' => 'علوم أولى إعدادي']),
        Product::factory()->create(['name' => 'المراجعة النهائية علوم أولى إعدادي']),
    ]);
});

test('BR-P10 a revision is linked to its main book, audited', function (): void {
    $this->tenantJson('POST', "/api/dashboard/products/{$this->revision->id}/relations", ['related_product_id' => $this->book->id, 'type' => 'revision_of'])
        ->assertCreated()
        ->assertExactJsonStructure(['data' => ['id', 'type', 'direction', 'product' => ['id', 'name', 'slug', 'status']]])
        ->assertJsonPath('data.type', 'revision_of')
        ->assertJsonPath('data.direction', 'outgoing')
        ->assertJsonPath('data.product.id', $this->book->id);

    $this->assertDatabaseHas('product_relations', ['tenant_id' => $this->tenant->id, 'product_id' => $this->revision->id, 'related_product_id' => $this->book->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'product_relation.added', 'actor_id' => $this->me->id]);
});

test('BR-P10 the relation shows on both products: outgoing on the revision, incoming on the book', function (): void {
    $this->inTenant(fn () => ProductRelation::factory()->revisionOf()->create(['product_id' => $this->revision->id, 'related_product_id' => $this->book->id]));

    $this->tenantJson('GET', "/api/dashboard/products/{$this->revision->id}/relations")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.direction', 'outgoing')
        ->assertJsonPath('data.0.product.id', $this->book->id);

    $this->tenantJson('GET', "/api/dashboard/products/{$this->book->id}/relations")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.direction', 'incoming')
        ->assertJsonPath('data.0.product.id', $this->revision->id);
});

test('BR-P10 a product cannot be related to itself', function (): void {
    $this->tenantJson('POST', "/api/dashboard/products/{$this->book->id}/relations", ['related_product_id' => $this->book->id, 'type' => 'companion'])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['related_product_id']]]);
});

test('BR-P10 the same relation cannot be added twice', function (): void {
    $this->inTenant(fn () => ProductRelation::factory()->create(['product_id' => $this->book->id, 'related_product_id' => $this->revision->id]));

    $this->tenantJson('POST', "/api/dashboard/products/{$this->book->id}/relations", ['related_product_id' => $this->revision->id, 'type' => 'companion'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'duplicate_relation');
});

test('BR-P10 the same pair may hold relations of different types', function (): void {
    $this->inTenant(fn () => ProductRelation::factory()->create(['product_id' => $this->book->id, 'related_product_id' => $this->revision->id]));

    $this->tenantJson('POST', "/api/dashboard/products/{$this->book->id}/relations", ['related_product_id' => $this->revision->id, 'type' => 'revision_of'])
        ->assertCreated();
});

test('relation validation', function (array $body, string $field): void {
    $this->tenantJson('POST', "/api/dashboard/products/{$this->book->id}/relations", $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing related product' => [['type' => 'companion'], 'related_product_id'],
    'unknown type' => [['related_product_id' => 1, 'type' => 'sibling'], 'type'],
    'missing type' => [['related_product_id' => 1], 'type'],
]);

test('BR-T04 a product of another tenant cannot be the related product', function (): void {
    $foreign = $this->inTenant(fn () => Product::factory()->create(), $this->otherTenant());

    $this->tenantJson('POST', "/api/dashboard/products/{$this->book->id}/relations", ['related_product_id' => $foreign->id, 'type' => 'companion'])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['related_product_id']]]);

    $this->assertDatabaseCount('product_relations', 0);
});

test('a relation is removed by the product that declared it, audited', function (): void {
    $relation = $this->inTenant(fn () => ProductRelation::factory()->create(['product_id' => $this->book->id, 'related_product_id' => $this->revision->id]));

    $this->tenantJson('DELETE', "/api/dashboard/products/{$this->book->id}/relations/{$relation->id}")->assertNoContent();

    $this->assertDatabaseMissing('product_relations', ['id' => $relation->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'product_relation.removed', 'entity_id' => $relation->id]);
});

test('a relation cannot be removed through the other product', function (): void {
    $relation = $this->inTenant(fn () => ProductRelation::factory()->create(['product_id' => $this->book->id, 'related_product_id' => $this->revision->id]));

    $this->tenantJson('DELETE', "/api/dashboard/products/{$this->revision->id}/relations/{$relation->id}")->assertNotFound();

    $this->assertDatabaseHas('product_relations', ['id' => $relation->id]);
});

test('BR-T04 relations of a product of another tenant are 404 and untouched', function (string $method, string $suffix): void {
    [$product, $relation] = $this->inTenant(function (): array {
        $product = Product::factory()->create();

        return [$product, ProductRelation::factory()->create(['product_id' => $product->id])];
    }, $this->otherTenant());

    $this->tenantJson($method, str_replace('{relation}', (string) $relation->id, "/api/dashboard/products/{$product->id}/relations{$suffix}"), ['related_product_id' => $this->book->id, 'type' => 'companion'])
        ->assertNotFound();

    $this->assertDatabaseHas('product_relations', ['id' => $relation->id]);
    $this->assertDatabaseCount('product_relations', 1);
})->with([
    'list' => ['GET', ''],
    'add' => ['POST', ''],
    'remove' => ['DELETE', '/{relation}'],
]);
