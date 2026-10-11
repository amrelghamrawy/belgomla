<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Resources\BulkStatusResultResource;
use App\Modules\Catalog\Http\Resources\DashboardProductResource;
use App\Modules\Catalog\Http\Resources\ProductImageResource;
use App\Modules\Catalog\Http\Resources\ProductRelationResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('the dashboard product resource key set without detail relations', function (): void {
    $product = Product::factory()->withSku(['cover_price' => '120', 'base_price' => '90'])->create(['custom_fields' => ['brand' => 'Bic']])
        ->refresh()->load(['category', 'sku.sellingUnit', 'sku.baseUnit', 'mainImage']);

    $data = rendered(new DashboardProductResource($product), dashboardRequest());

    expect(array_keys($data))->toBe([
        'id', 'name', 'slug', 'description', 'status', 'category', 'custom_fields', 'sku', 'main_image_url', 'created_at', 'updated_at',
    ])
        ->and(array_keys($data['sku']))->toBe([
            'id', 'code', 'selling_unit', 'base_unit', 'conversion_factor', 'cover_price', 'base_price', 'base_price_percent', 'moq',
        ])
        ->and($data['sku']['cover_price'])->toBe('120.00')
        ->and($data['sku']['base_price'])->toBe('90.00')
        ->and($data['custom_fields'])->toBe(['brand' => 'Bic'])
        ->and($data['category'])->toBe(['id' => $product->category->id, 'name' => $product->category->name, 'kind' => 'general'])
        ->and($data['created_at'])->toBe($product->created_at->toIso8601String());
});

test('the dashboard sku lists its tiers with the percent each was entered as once they are loaded', function (): void {
    $product = Product::factory()->withSku()->create()->load('sku');
    App\Modules\Pricing\Models\SkuPriceTier::factory()->for($product->sku)->create(['min_quantity' => 10, 'unit_price' => '90.00']);
    $product->load('sku.tiers');

    $sku = rendered(new DashboardProductResource($product), dashboardRequest())['sku'];

    expect($sku['tiers'])->toBe([['min_quantity' => 10, 'unit_price' => '90.00', 'discount_percent' => null]]);
});

test('the dashboard product resource never lazy-loads: unloaded relations render as null', function (): void {
    $product = Product::factory()->withSku()->create()->refresh();

    $data = rendered(new DashboardProductResource($product), dashboardRequest());

    expect($data['category'])->toBeNull()
        ->and($data['sku'])->toBeNull()
        ->and($data['main_image_url'])->toBeNull();
});

test('a sku with unloaded units renders null units', function (): void {
    $product = Product::factory()->withSku()->create()->refresh()->load('sku');

    $data = rendered(new DashboardProductResource($product), dashboardRequest());

    expect($data['sku']['selling_unit'])->toBeNull()
        ->and($data['sku']['base_unit'])->toBeNull();
});

test('empty custom fields render as a json object', function (): void {
    $product = Product::factory()->create()->refresh();

    $json = (string) new DashboardProductResource($product)->toResponse(dashboardRequest())->getContent();

    expect($json)->toContain('"custom_fields":{}');
});

test('the image resource key set', function (): void {
    $image = ProductImage::factory()->main()->create(['path' => 'p/a.jpg', 'sort_order' => 3]);

    expect(rendered(new ProductImageResource($image), dashboardRequest()))->toBe([
        'id' => $image->id, 'url' => $image->url(), 'is_main' => true, 'sort_order' => 3,
    ]);
});

test('BR-P10 the relation resource shows the other side and the direction from each product', function (): void {
    $relation = ProductRelation::factory()->revisionOf()->create()->load('product', 'relatedProduct');

    $fromRevision = rendered(new ProductRelationResource($relation, $relation->product_id), dashboardRequest());
    $fromBook = rendered(new ProductRelationResource($relation, $relation->related_product_id), dashboardRequest());

    expect(array_keys($fromRevision))->toBe(['id', 'type', 'direction', 'product'])
        ->and(array_keys($fromRevision['product']))->toBe(['id', 'name', 'slug', 'status'])
        ->and($fromRevision['direction'])->toBe('outgoing')
        ->and($fromRevision['product']['id'])->toBe($relation->related_product_id)
        ->and($fromBook['direction'])->toBe('incoming')
        ->and($fromBook['product']['id'])->toBe($relation->product_id);
});

test('the bulk result resource key set', function (): void {
    expect(rendered(new BulkStatusResultResource(['affected' => 2, 'skipped' => 1, 'batch_id' => 'b']), dashboardRequest()))
        ->toBe(['affected' => 2, 'skipped' => 1, 'batch_id' => 'b']);
});
