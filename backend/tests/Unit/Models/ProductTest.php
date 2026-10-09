<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Models\Scopes\VisibleOnStore;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Services\FacetCacheVersion;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $product = $this->inTenant(fn () => Product::factory()->create()->fresh());

    expect(array_keys($product->toArray()))->toBe([
        'id', 'tenant_id', 'category_id', 'name', 'slug', 'description', 'status', 'custom_fields', 'created_by', 'created_at', 'updated_at',
    ]);
});

test('casts the status and the custom fields map', function (): void {
    $product = $this->inTenant(fn () => Product::factory()->comingSoon()->create(['custom_fields' => ['grade' => 12, 'brand' => 'Bic']])->fresh());

    expect($product->status)->toBe(ProductStatus::ComingSoon)
        ->and($product->custom_fields)->toBe(['brand' => 'Bic', 'grade' => 12]);
});

test('a product created without status or custom fields is active with an empty map', function (): void {
    $product = $this->inTenant(function (): Product {
        $category = Category::factory()->create();

        return Product::query()->create(['category_id' => $category->id, 'name' => 'قلم'])->fresh();
    });

    expect($product->status)->toBe(ProductStatus::Active)
        ->and($product->custom_fields)->toBe([]);
});

test('the slug is generated from the arabic name and kept unique per tenant', function (): void {
    [$first, $second] = $this->inTenant(fn (): array => [
        Product::factory()->create(['name' => 'علوم أولى إعدادي']),
        Product::factory()->create(['name' => 'علوم أولى إعدادي']),
    ]);

    expect($first->slug)->toBe('علوم-أولى-إعدادي')
        ->and($second->slug)->toBe('علوم-أولى-إعدادي-2');
});

test('the same slug is allowed in another tenant', function (): void {
    $this->inTenant(fn () => Product::factory()->create(['name' => 'قلم']));
    $foreign = $this->inTenant(fn () => Product::factory()->create(['name' => 'قلم']), $this->otherTenant());

    expect($foreign->slug)->toBe('قلم');
});

test('BR-P02 the sku relation returns the single sku', function (): void {
    $product = $this->inTenant(fn () => Product::factory()->withSku(['code' => 'PEN-1'])->create());

    expect($product->sku)->toBeInstanceOf(Sku::class)
        ->and($product->sku->code)->toBe('PEN-1')
        ->and($product->skus)->toHaveCount(1);
});

test('images are ordered and the main image is exposed', function (): void {
    [$product, $main, $gallery] = $this->inTenant(function (): array {
        $product = Product::factory()->create();

        return [
            $product,
            ProductImage::factory()->for($product)->main()->create(['sort_order' => 2]),
            ProductImage::factory()->for($product)->create(['sort_order' => 1]),
        ];
    });

    expect($product->images->pluck('id')->all())->toBe([$gallery->id, $main->id])
        ->and($product->mainImage->is($main))->toBeTrue();
});

test('BR-P10 outgoing and incoming relations', function (): void {
    [$book, $revision, $relation] = $this->inTenant(function (): array {
        $book = Product::factory()->create();
        $revision = Product::factory()->create();

        $relation = ProductRelation::factory()->revisionOf()->create(['product_id' => $revision->id, 'related_product_id' => $book->id]);

        return [$book->load('relations', 'incomingRelations'), $revision->load('relations'), $relation];
    });

    expect($revision->relations->pluck('id')->all())->toBe([$relation->id])
        ->and($book->incomingRelations->pluck('id')->all())->toBe([$relation->id])
        ->and($book->relations)->toBeEmpty();
});

test('BR-P04 the VisibleOnStore scope hides inactive products only', function (): void {
    [$active, $comingSoon] = $this->inTenant(function (): array {
        Product::factory()->inactive()->create();

        return [Product::factory()->create(), Product::factory()->comingSoon()->create()];
    });

    $ids = $this->inTenant(fn () => Product::query()->tap(new VisibleOnStore)->orderBy('id')->pluck('id')->all());

    expect($ids)->toBe([$active->id, $comingSoon->id]);
});

test('saving a product bumps the facet cache version of its tenant', function (): void {
    $versions = app(FacetCacheVersion::class);
    $before = $versions->current($this->tenant->id);

    $product = $this->inTenant(fn () => Product::factory()->create());
    $afterCreate = $versions->current($this->tenant->id);
    $this->inTenant(fn () => $product->update(['name' => 'جديد']));

    expect($afterCreate)->toBeGreaterThan($before)
        ->and($versions->current($this->tenant->id))->toBeGreaterThan($afterCreate);
});

test('deleting a product bumps the facet cache version', function (): void {
    $product = $this->inTenant(fn () => Product::factory()->create());
    $before = app(FacetCacheVersion::class)->current($this->tenant->id);

    $this->inTenant(fn () => $product->delete());

    expect(app(FacetCacheVersion::class)->current($this->tenant->id))->toBe($before + 1);
});
