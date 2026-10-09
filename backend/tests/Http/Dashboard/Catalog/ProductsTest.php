<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;

const DASHBOARD_PRODUCT_KEYS = [
    'id', 'name', 'slug', 'description', 'status', 'category' => ['id', 'name'], 'custom_fields',
    'sku' => ['id', 'code', 'selling_unit' => ['id', 'name'], 'base_unit' => ['id', 'name'], 'conversion_factor', 'cover_price', 'base_price', 'base_price_percent', 'moq'],
    'main_image_url', 'created_at', 'updated_at',
];

/** The detail (show, create, update) also carries the tiers and the stock (08-api-contract.md §6). */
const DASHBOARD_PRODUCT_DETAIL_KEYS = [
    ...DASHBOARD_PRODUCT_KEYS,
    'sku' => [...DASHBOARD_PRODUCT_KEYS['sku'], 'tiers', 'inventory' => ['on_hand', 'reserved', 'available']],
];

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

test('BR-P02 creating a product creates exactly one sku in the same call, audited', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload())
        ->assertCreated()
        ->assertExactJsonStructure(['data' => [...DASHBOARD_PRODUCT_DETAIL_KEYS, 'images', 'relations']])
        ->assertJsonPath('data.name', 'الأضواء علوم أولى إعدادي ترم أول')
        ->assertJsonPath('data.slug', 'الأضواء-علوم-أولى-إعدادي-ترم-أول')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.sku.code', 'ADW-SCI-P1-T1')
        ->assertJsonPath('data.sku.selling_unit.name', 'كرتونة')
        ->assertJsonPath('data.sku.base_unit.name', 'قطعة')
        ->assertJsonPath('data.sku.conversion_factor', 20)
        ->assertJsonPath('data.sku.cover_price', '120.00')
        ->assertJsonPath('data.sku.base_price', '90.00')
        ->assertJsonPath('data.sku.moq', 5)
        ->assertJsonPath('data.images', [])
        ->assertJsonPath('data.relations', []);

    $id = $response->json('data.id');

    expect(Sku::withoutTenantScope()->where('product_id', $id)->count())->toBe(1);
    $this->assertDatabaseHas('products', ['id' => $id, 'tenant_id' => $this->tenant->id, 'created_by' => $this->me->id]);
    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'product.created', 'entity_id' => $id, 'actor_id' => $this->me->id]);
});

test('BR-MOQ01 the moq defaults to 1 and prices are normalised to two decimals', function (): void {
    $payload = $this->productPayload(['sku' => ['base_price' => 90, 'cover_price' => '120.5']]);
    unset($payload['sku']['moq']);

    $this->tenantJson('POST', '/api/dashboard/products', $payload)
        ->assertCreated()
        ->assertJsonPath('data.sku.moq', 1)
        ->assertJsonPath('data.sku.base_price', '90.00')
        ->assertJsonPath('data.sku.cover_price', '120.50');
});

test('a product can be created as coming soon', function (): void {
    $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload(['status' => 'coming_soon']))
        ->assertCreated()
        ->assertJsonPath('data.status', 'coming_soon');
});

test('BR-P08 custom fields are validated and stored with select option ids', function (): void {
    [$grade, $option] = $this->inTenant(function (): array {
        $grade = ProductFieldDefinition::factory()->select()->create(['key' => 'grade']);
        ProductFieldDefinition::factory()->create(['key' => 'isbn']);

        return [$grade, ProductFieldOption::factory()->for($grade, 'definition')->create(['label' => 'أولى إعدادي'])];
    });

    $id = $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload([
        'custom_fields' => ['grade' => $option->id, 'isbn' => ' 978-1 '],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.custom_fields', ['grade' => $option->id, 'isbn' => '978-1'])
        ->json('data.id');

    expect(Product::withoutTenantScope()->findOrFail($id)->custom_fields)->toEqual(['grade' => $option->id, 'isbn' => '978-1']);
});

test('BR-P08 invalid custom fields are rejected per key', function (array $customFields, string $key): void {
    $this->inTenant(function (): void {
        ProductFieldDefinition::factory()->select()->required()->create(['key' => 'grade']);
        ProductFieldDefinition::factory()->number()->create(['key' => 'pages']);
    });

    $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload(['custom_fields' => $customFields]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ["custom_fields.{$key}"]]]);

    $this->assertDatabaseCount('products', 0);
})->with([
    'required field missing' => [[], 'grade'],
    'unknown key' => [['colour' => 'red', 'grade' => 1], 'colour'],
    'number is not numeric' => [['pages' => 'many'], 'pages'],
    'option of nothing' => [['grade' => 999999], 'grade'],
]);

test('product creation validation', function (array $overrides, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);

    $this->assertDatabaseCount('products', 0);
})->with([
    'missing name' => [['name' => null], 'name'],
    'name too long' => [['name' => str_repeat('a', 201)], 'name'],
    'D25 missing category' => [['category_id' => null], 'category_id'],
    'custom fields not a map' => [['custom_fields' => 'grade=1'], 'custom_fields'],
    'missing sku code' => [['sku' => ['code' => null]], 'sku.code'],
    'sku code with spaces' => [['sku' => ['code' => 'ADW SCI']], 'sku.code'],
    'sku code too long' => [['sku' => ['code' => str_repeat('A', 61)]], 'sku.code'],
    'missing selling unit' => [['sku' => ['selling_unit_id' => null]], 'sku.selling_unit_id'],
    'missing base unit' => [['sku' => ['base_unit_id' => null]], 'sku.base_unit_id'],
    'BR-U05 fractional conversion factor' => [['sku' => ['conversion_factor' => 1.5]], 'sku.conversion_factor'],
    'BR-U02 conversion factor below 1' => [['sku' => ['conversion_factor' => 0]], 'sku.conversion_factor'],
    'BR-PR01 missing base price' => [['sku' => ['base_price' => null]], 'sku.base_price'],
    'BR-PR01 zero base price' => [['sku' => ['base_price' => 0]], 'sku.base_price'],
    'base price with 3 decimals' => [['sku' => ['base_price' => '1.005']], 'sku.base_price'],
    'negative cover price' => [['sku' => ['cover_price' => -1]], 'sku.cover_price'],
    'BR-PR03 tier without a minimum quantity' => [['sku' => ['tiers' => [['unit_price' => 80]]]], 'sku.tiers.0.min_quantity'],
    'BR-PR03 tier without a price or percent' => [['sku' => ['tiers' => [['min_quantity' => 10]]]], 'sku.tiers.0.unit_price'],
    'BR-PR05 tier price of 0' => [['sku' => ['tiers' => [['min_quantity' => 10, 'unit_price' => 0]]]], 'sku.tiers.0.unit_price'],
    'BR-U05 fractional tier quantity' => [['sku' => ['tiers' => [['min_quantity' => 2.5, 'unit_price' => 80]]]], 'sku.tiers.0.min_quantity'],
    'BR-PR10 tier percent of 100' => [['sku' => ['tiers' => [['min_quantity' => 10, 'discount_percent' => 100]]]], 'sku.tiers.0.discount_percent'],
    'tiers not a list' => [['sku' => ['tiers' => 'x']], 'sku.tiers'],
    'BR-PR10 percent of 100' => [['sku' => ['base_price_percent' => 100]], 'sku.base_price_percent'],
    'BR-MOQ01 moq of 0' => [['sku' => ['moq' => 0]], 'sku.moq'],
    'BR-U05 fractional moq' => [['sku' => ['moq' => 2.5]], 'sku.moq'],
    'unknown status' => [['status' => 'draft'], 'status'],
    'BR-INV02 negative opening stock' => [['opening_stock' => -1], 'opening_stock'],
    'BR-U05 fractional opening stock' => [['opening_stock' => 2.5], 'opening_stock'],
    'opening stock too large' => [['opening_stock' => 10_000_001], 'opening_stock'],
]);

test('BR-PR10 a base price percentage is stored next to the price computed from the cover price', function (): void {
    $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload(['sku' => ['base_price' => null, 'base_price_percent' => 20]]))
        ->assertCreated()
        ->assertJsonPath('data.sku.base_price', '96.00')
        ->assertJsonPath('data.sku.base_price_percent', '20.00');
});

test('BR-PR10 a percentage without a cover price is refused with cover_price_required', function (): void {
    $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload(['sku' => ['cover_price' => null, 'base_price_percent' => 25]]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'cover_price_required');

    expect($this->inTenant(fn () => Product::query()->count()))->toBe(0);
});

test('BR-PR03 a product is created with its tiers, absolute or as a percent off the cover price', function (): void {
    $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload(['sku' => ['tiers' => [
        ['min_quantity' => 50, 'discount_percent' => 35],
        ['min_quantity' => 10, 'unit_price' => '85'],
    ]]]))
        ->assertCreated()
        ->assertJsonPath('data.sku.tiers', [
            ['min_quantity' => 10, 'unit_price' => '85.00', 'discount_percent' => null],
            ['min_quantity' => 50, 'unit_price' => '78.00', 'discount_percent' => '35.00'],
        ]);
});

test('BR-PR05 duplicate tier thresholds on a product are refused with invalid_tiers', function (): void {
    $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload(['sku' => ['tiers' => [
        ['min_quantity' => 10, 'unit_price' => '85'],
        ['min_quantity' => 10, 'unit_price' => '80'],
    ]]]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_tiers')
        ->assertJsonCount(1, 'error.details.tiers');
});

test('BR-INV06 the opening stock is written as an opening movement and in the creation audit', function (): void {
    $id = $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload(['opening_stock' => 100]))
        ->assertCreated()
        ->assertJsonPath('data.sku.inventory', ['on_hand' => 100, 'reserved' => 0, 'available' => 100])
        ->assertJsonMissingPath('data.opening_stock')
        ->json('data.id');

    $skuId = $this->inTenant(fn () => Sku::query()->where('product_id', $id)->sole()->id);

    $this->assertDatabaseHas('inventory_movements', ['sku_id' => $skuId, 'type' => 'opening', 'quantity' => 100, 'on_hand_after' => 100, 'actor_type' => 'tenant_user', 'actor_id' => $this->me->id]);
    expect($this->inTenant(fn () => AuditLog::query()->where('action', 'product.created')->sole()->new_values))->toMatchArray(['opening_stock' => 100]);
});

test('a product created without opening stock starts with an empty stock row and no movement', function (): void {
    $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload())
        ->assertCreated()
        ->assertJsonPath('data.sku.inventory', ['on_hand' => 0, 'reserved' => 0, 'available' => 0]);

    $this->assertDatabaseCount('sku_inventory', 1);
    $this->assertDatabaseCount('inventory_movements', 0);
});

test('the opening stock is create-only: an update ignores it', function (): void {
    $id = $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload(['opening_stock' => 10]))->json('data.id');

    $this->tenantJson('PUT', "/api/dashboard/products/{$id}", $this->productPayload(['opening_stock' => 99]))
        ->assertOk()
        ->assertJsonPath('data.sku.inventory.on_hand', 10);

    $this->assertDatabaseCount('inventory_movements', 1);
});

test('BR-P03 a sku code already used in the tenant conflicts', function (): void {
    $this->inTenant(fn () => Sku::factory()->create(['code' => 'ADW-SCI-P1-T1']));

    $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload())
        ->assertConflict()
        ->assertJsonPath('error.code', 'duplicate_code');

    $this->assertDatabaseCount('products', 1);
});

test('BR-P03 the same sku code is allowed when another tenant uses it', function (): void {
    $this->inTenant(fn () => Sku::factory()->create(['code' => 'ADW-SCI-P1-T1']), $this->otherTenant());

    $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload())->assertCreated();
});

test('BR-T04 references of another tenant are rejected as unknown', function (string $field): void {
    $other = $this->otherTenant();
    $foreign = $this->inTenant(fn (): array => [
        'category_id' => Category::factory()->create()->id,
        'sku.selling_unit_id' => Unit::factory()->create()->id,
        'sku.base_unit_id' => Unit::factory()->create()->id,
    ], $other);

    $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload(Illuminate\Support\Arr::undot([$field => $foreign[$field]])))
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);

    $this->assertDatabaseCount('products', 0);
})->with(['category_id', 'sku.selling_unit_id', 'sku.base_unit_id']);

test('archived categories and units cannot be chosen for a new product', function (string $field): void {
    $archived = $this->inTenant(fn (): array => [
        'category_id' => Category::factory()->archived()->create()->id,
        'sku.selling_unit_id' => Unit::factory()->archived()->create()->id,
    ]);

    $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload(Illuminate\Support\Arr::undot([$field => $archived[$field]])))
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with(['category_id', 'sku.selling_unit_id']);

test('the dashboard list shows every status, newest first, paginated with meta', function (): void {
    [$active, $inactive] = $this->inTenant(fn (): array => [
        Product::factory()->withSku()->create(['created_at' => now()->subDay()]),
        Product::factory()->withSku()->inactive()->create(),
    ]);
    $this->inTenant(fn () => Product::factory()->withSku()->create(), $this->otherTenant());

    $this->tenantJson('GET', '/api/dashboard/products')
        ->assertOk()
        ->assertExactJsonStructure([
            'data' => ['*' => DASHBOARD_PRODUCT_KEYS],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'],
        ])
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 24)
        ->assertJsonPath('data.0.id', $inactive->id)
        ->assertJsonPath('data.0.status', 'inactive')
        ->assertJsonPath('data.1.id', $active->id);
});

test('the dashboard list filters by status, category with descendants, field and search', function (string $query, string $expected): void {
    $ids = $this->inTenant(function (): array {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();
        $grade = ProductFieldDefinition::factory()->select()->create(['key' => 'grade']);
        $first = ProductFieldOption::factory()->for($grade, 'definition')->create(['label' => 'أولى إعدادي']);

        return [
            'in child' => Product::factory()->withSku(['code' => 'CHILD-1'])->create(['category_id' => $child->id, 'name' => 'علوم']),
            'inactive' => Product::factory()->withSku()->inactive()->create(['name' => 'رياضيات']),
            'graded' => Product::factory()->withSku()->create(['name' => 'دراسات', 'custom_fields' => ['grade' => $first->id]]),
            'root' => $root,
            'option' => $first,
        ];
    });

    $uri = strtr($query, ['{root}' => (string) $ids['root']->id, '{option}' => (string) $ids['option']->id]);

    $this->tenantJson('GET', "/api/dashboard/products?{$uri}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $ids[$expected]->id);
})->with([
    'status' => ['status=inactive', 'inactive'],
    'category includes descendants' => ['category={root}', 'in child'],
    'custom field' => ['f[grade]={option}', 'graded'],
    'search by name' => ['q=علوم', 'in child'],
    'search by sku code' => ['q=child-1', 'in child'],
    'search by option label' => ['q=اولى اعدادي', 'graded'],
]);

test('the dashboard list sorts by name and price', function (string $sort, array $order): void {
    $ids = $this->inTenant(fn (): array => [
        'b' => Product::factory()->withSku(['base_price' => '50.00'])->create(['name' => 'ب'])->id,
        'a' => Product::factory()->withSku(['base_price' => '70.00'])->create(['name' => 'أ'])->id,
    ]);

    $this->tenantJson('GET', "/api/dashboard/products?sort={$sort}")
        ->assertOk()
        ->assertJsonPath('data.0.id', $ids[$order[0]])
        ->assertJsonPath('data.1.id', $ids[$order[1]]);
})->with([
    'name' => ['name', ['a', 'b']],
    'price ascending' => ['price', ['b', 'a']],
    'price descending' => ['-price', ['a', 'b']],
]);

test('D36 low_stock=1 keeps products whose stock is at most one minimum order, in base units', function (): void {
    $ids = $this->inTenant(fn (): array => [
        'low' => Product::factory()->withSku(['moq' => 5, 'conversion_factor' => 20], onHand: 120, reserved: 20)->create()->id,
        'no stock row' => Product::factory()->withSku()->create()->id,
        'plenty' => Product::factory()->withSku(['moq' => 5, 'conversion_factor' => 20], onHand: 101)->create()->id,
    ]);
    $this->inTenant(fn () => Product::factory()->withSku(onHand: 0)->create(), $this->otherTenant());

    $listed = $this->tenantJson('GET', '/api/dashboard/products?low_stock=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->json('data.*.id');

    expect($listed)->toEqualCanonicalizing([$ids['low'], $ids['no stock row']]);
});

test('dashboard list validation', function (string $query, string $field): void {
    $this->tenantJson('GET', "/api/dashboard/products?{$query}")
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'unknown sort' => ['sort=stock', 'sort'],
    'per page over 100' => ['per_page=101', 'per_page'],
    'unknown status' => ['status=draft', 'status'],
    'category not an id' => ['category=books', 'category'],
    'field filter not a map' => ['f=1', 'f'],
    'low stock not a boolean' => ['low_stock=maybe', 'low_stock'],
]);

test('the product detail includes the stock, the gallery and relations both ways', function (): void {
    [$product, $image, $revision] = $this->inTenant(function (): array {
        $product = Product::factory()->withSku(onHand: 50, reserved: 8)->create();
        $revision = Product::factory()->create();
        App\Modules\Catalog\Models\ProductRelation::factory()->revisionOf()->create(['product_id' => $revision->id, 'related_product_id' => $product->id]);

        return [$product, ProductImage::factory()->for($product)->main()->create(), $revision];
    });

    $this->tenantJson('GET', "/api/dashboard/products/{$product->id}")
        ->assertOk()
        ->assertExactJsonStructure(['data' => [
            ...DASHBOARD_PRODUCT_DETAIL_KEYS,
            'images' => ['*' => ['id', 'url', 'is_main', 'sort_order']],
            'relations' => ['*' => ['id', 'type', 'direction', 'product' => ['id', 'name', 'slug', 'status']]],
        ]])
        ->assertJsonPath('data.sku.inventory', ['on_hand' => 50, 'reserved' => 8, 'available' => 42])
        ->assertJsonPath('data.images.0.id', $image->id)
        ->assertJsonPath('data.main_image_url', $image->url())
        ->assertJsonPath('data.relations.0.direction', 'incoming')
        ->assertJsonPath('data.relations.0.type', 'revision_of')
        ->assertJsonPath('data.relations.0.product.id', $revision->id);
});

test('an owner updates product and sku fields; the audit keeps only what changed', function (): void {
    $id = $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload())->json('data.id');

    $this->tenantJson('PUT', "/api/dashboard/products/{$id}", $this->productPayload(['name' => 'اسم جديد', 'sku' => ['base_price' => '85.00', 'moq' => 10]]))
        ->assertOk()
        ->assertJsonPath('data.name', 'اسم جديد')
        ->assertJsonPath('data.sku.base_price', '85.00')
        ->assertJsonPath('data.sku.moq', 10)
        ->assertJsonPath('data.slug', 'الأضواء-علوم-أولى-إعدادي-ترم-أول');

    $logs = $this->inTenant(fn () => AuditLog::query()->whereIn('action', ['product.updated', 'sku.price_changed', 'sku.moq_changed'])->get()->keyBy('action'));

    expect($logs['product.updated']->old_values)->toEqual(['name' => 'الأضواء علوم أولى إعدادي ترم أول'])
        ->and($logs['product.updated']->new_values)->toEqual(['name' => 'اسم جديد'])
        ->and($logs['sku.price_changed']->new_values)->toEqual(['base_price' => '85.00'])
        ->and($logs['sku.moq_changed']->new_values)->toEqual(['moq' => 10])
        ->and($logs->pluck('actor_id')->unique()->all())->toBe([$this->me->id]);
});

test('an update may keep its own sku code but not take another product code', function (): void {
    $id = $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload())->json('data.id');
    $this->inTenant(fn () => Sku::factory()->create(['code' => 'TAKEN']));

    $this->tenantJson('PUT', "/api/dashboard/products/{$id}", $this->productPayload())->assertOk();

    $this->tenantJson('PUT', "/api/dashboard/products/{$id}", $this->productPayload(['sku' => ['code' => 'TAKEN']]))
        ->assertConflict()
        ->assertJsonPath('error.code', 'duplicate_code');
});

test('the status cannot be changed through the product update', function (): void {
    $id = $this->tenantJson('POST', '/api/dashboard/products', $this->productPayload())->json('data.id');

    $this->tenantJson('PUT', "/api/dashboard/products/{$id}", $this->productPayload(['status' => 'inactive']))
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['status']]]);
});

test('BR-T04 a product of another tenant is 404 on show and update, and untouched', function (string $method): void {
    $foreign = $this->inTenant(fn () => Product::factory()->withSku()->create(['name' => 'original']), $this->otherTenant());

    $this->tenantJson($method, "/api/dashboard/products/{$foreign->id}", $this->productPayload(['name' => 'hacked']))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('products', ['id' => $foreign->id, 'name' => 'original']);
})->with(['GET', 'PUT']);
