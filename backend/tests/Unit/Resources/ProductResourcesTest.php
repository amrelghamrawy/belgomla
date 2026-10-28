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
        ->refresh()->load(['category', 'sku.activeUnits.unit', 'sku.baseUnit', 'mainImage']);

    $data = rendered(new DashboardProductResource($product), dashboardRequest());

    expect(array_keys($data))->toBe([
        'id', 'name', 'slug', 'description', 'status', 'category', 'custom_fields', 'preorder', 'sku', 'main_image_url', 'created_at', 'updated_at',
    ])
        ->and($data['preorder'])->toBe(['enabled' => false, 'expected_at' => null, 'limit' => null])
        ->and(array_keys($data['sku']))->toBe([
            'id', 'code', 'base_unit', 'selling_unit', 'conversion_factor', 'cover_price', 'base_price', 'base_price_percent', 'moq', 'units',
        ])
        ->and($data['sku']['cover_price'])->toBe('120.00')
        ->and($data['sku']['base_price'])->toBe('90.00')
        ->and($data['sku']['selling_unit'])->toBe(['id' => $product->sku?->activeUnits->sole()->unit_id, 'name' => $product->sku?->activeUnits->sole()->unit->name])
        ->and(array_keys($data['sku']['units'][0]))->toBe(['id', 'unit', 'conversion_factor', 'cover_price', 'base_price', 'base_price_percent', 'moq', 'is_default'])
        ->and($data['custom_fields'])->toBe(['brand' => 'Bic'])
        ->and($data['category'])->toBe(['id' => $product->category->id, 'name' => $product->category->name, 'kind' => 'general'])
        ->and($data['created_at'])->toBe($product->created_at->toIso8601String());
});

test('D91 the dashboard sku lists its active units, the default first, each with its tiers once they are loaded', function (): void {
    $product = Product::factory()->withSku(['base_price' => '9.00'])->create();
    $sku = $product->sku()->sole();
    App\Modules\Pricing\Models\SkuPriceTier::factory()->create(['sku_id' => $sku->id, 'min_quantity' => 10, 'unit_price' => '8.00']);
    $carton = $this->addUnit($sku, ['cover_price' => '120.00', 'base_price' => '100.00', 'base_price_percent' => '20.00', 'moq' => 2], [5 => '95.00']);
    App\Modules\Catalog\Models\SkuUnit::factory()->archived()->create(['sku_id' => $sku->id]);
    $product->load('sku.activeUnits.unit', 'sku.activeUnits.tiers');

    $units = rendered(new DashboardProductResource($product), dashboardRequest())['sku']['units'];

    expect($units)->toHaveCount(2)
        ->and($units[0]['tiers'])->toBe([['min_quantity' => 10, 'unit_price' => '8.00', 'discount_percent' => null]])
        ->and($units[1])->toBe([
            'id' => $carton->id,
            'unit' => ['id' => $carton->unit_id, 'name' => $carton->unit->name],
            'conversion_factor' => 12,
            'cover_price' => '120.00',
            'base_price' => '100.00',
            'base_price_percent' => '20.00',
            'moq' => 2,
            'is_default' => false,
            'tiers' => [['min_quantity' => 5, 'unit_price' => '95.00', 'discount_percent' => null]],
        ]);
});

test('the dashboard product resource never lazy-loads: unloaded relations render as null', function (): void {
    $product = Product::factory()->withSku()->create()->refresh();

    $data = rendered(new DashboardProductResource($product), dashboardRequest());

    expect($data['category'])->toBeNull()
        ->and($data['sku'])->toBeNull()
        ->and($data['main_image_url'])->toBeNull();
});

test('a sku with unloaded units renders null units, null default-unit fields and no units list', function (): void {
    $product = Product::factory()->withSku()->create()->refresh()->load('sku');

    $data = rendered(new DashboardProductResource($product), dashboardRequest());

    expect($data['sku']['selling_unit'])->toBeNull()
        ->and($data['sku']['base_unit'])->toBeNull()
        ->and($data['sku']['base_price'])->toBeNull()
        ->and($data['sku'])->not->toHaveKey('units');
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
