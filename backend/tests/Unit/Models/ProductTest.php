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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $product = $this->inTenant(fn () => Product::factory()->create()->fresh());

    expect(array_keys($product->toArray()))->toBe([
        'id', 'tenant_id', 'category_id', 'name', 'slug', 'description', 'status', 'custom_fields', 'created_by', 'created_at', 'updated_at',
        'preorder_enabled', 'expected_at', 'preorder_limit', 'type',
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

test('BR-P02 BR-P14 a product without options has exactly one sku', function (): void {
    $product = $this->inTenant(fn () => Product::factory()->withSku(['code' => 'PEN-1'])->create());

    expect($product->skus->first())->toBeInstanceOf(Sku::class)
        ->and($product->skus->first()->code)->toBe('PEN-1')
        ->and($product->skus)->toHaveCount(1);
});

test('BR-P14 skus are in the form order, active skus leave the inactive out, options are ordered', function (): void {
    $product = $this->variantProduct(['أزرق', 'أسود', 'أحمر']);
    $this->inTenant(function () use ($product): void {
        Sku::query()->where('code', 'PEN-1')->update(['sort_order' => 5]);
        Sku::query()->where('code', 'PEN-2')->update(['status' => 'inactive']);
        $product->options()->create(['name' => 'المقاس', 'sort_order' => 1]);
    });

    $product = $this->inTenant(fn () => $product->refresh()->load('skus', 'activeSkus', 'options'));

    expect($product->skus->pluck('code')->all())->toBe(['PEN-2', 'PEN-3', 'PEN-1'])
        ->and($product->activeSkus->pluck('code')->all())->toBe(['PEN-3', 'PEN-1'])
        ->and($product->options->pluck('name')->all())->toBe(['اللون', 'المقاس']);
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

test('BR-O14 pre-orders are off by default; the settings are cast', function (): void {
    $plain = $this->inTenant(fn () => Product::factory()->create()->fresh());
    $preorderable = $this->inTenant(fn () => Product::factory()->preorderable(limit: 50, expectedAt: '2026-12-01')->create()->fresh());

    expect($plain->preorder_enabled)->toBeFalse()
        ->and($plain->expected_at)->toBeNull()
        ->and($plain->preorder_limit)->toBeNull()
        ->and($preorderable->preorder_enabled)->toBeTrue()
        ->and($preorderable->expected_at?->toDateString())->toBe('2026-12-01')
        ->and($preorderable->preorder_limit)->toBe(50);
});

test('BR-O14 the database refuses a pre-order cap below one', function (): void {
    $product = $this->inTenant(fn () => Product::factory()->create());

    DB::table('products')->where('id', $product->id)->update(['preorder_limit' => 0]);
})->throws(QueryException::class, 'products_preorder_limit_positive');

test('BR-P15 a product is simple unless made a bundle', function (): void {
    [$simple, $bundle] = $this->inTenant(fn (): array => [Product::factory()->create()->fresh(), Product::factory()->bundle()->create()->fresh()]);

    expect($simple->type)->toBe(App\Modules\Catalog\Enums\ProductType::Simple)
        ->and($simple->isBundle())->toBeFalse()
        ->and($bundle->isBundle())->toBeTrue();
});
