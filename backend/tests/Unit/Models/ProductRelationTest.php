<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\RelationType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $relation = $this->inTenant(fn () => ProductRelation::factory()->create()->fresh());

    expect(array_keys($relation->toArray()))->toBe([
        'id', 'tenant_id', 'product_id', 'related_product_id', 'type', 'created_at', 'updated_at',
    ]);
});

test('casts the type and resolves both products', function (): void {
    [$book, $revision, $relation] = $this->inTenant(function (): array {
        $book = Product::factory()->create();
        $revision = Product::factory()->create();

        return [$book, $revision, ProductRelation::factory()->revisionOf()->create([
            'product_id' => $revision->id, 'related_product_id' => $book->id,
        ])->fresh()];
    });

    expect($relation->type)->toBe(RelationType::RevisionOf)
        ->and($relation->product->is($revision))->toBeTrue()
        ->and($relation->relatedProduct->is($book))->toBeTrue();
});

test('BR-P10 the database refuses a product related to itself', function (): void {
    $this->inTenant(function (): void {
        $product = Product::factory()->create();
        ProductRelation::factory()->create(['product_id' => $product->id, 'related_product_id' => $product->id]);
    });
})->throws(QueryException::class, 'product_relations_not_self');
