<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\CategoryIcon;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

const DASHBOARD_CATEGORY_KEYS = ['id', 'name', 'slug', 'kind', 'icon', 'image_url', 'parent_id', 'sort_order', 'status', 'children'];

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

test('the dashboard tree lists every category with children, archived included', function (): void {
    [$root, $child, $archived] = $this->inTenant(function (): array {
        $root = Category::factory()->create(['name' => 'ابتدائي', 'sort_order' => 1]);

        return [$root, Category::factory()->childOf($root)->create(), Category::factory()->archived()->create(['sort_order' => 2])];
    });

    $this->tenantJson('GET', '/api/dashboard/categories')
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['*' => DASHBOARD_CATEGORY_KEYS]])
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $root->id)
        ->assertJsonPath('data.0.children.0.id', $child->id)
        ->assertJsonPath('data.0.children.0.parent_id', $root->id)
        ->assertJsonPath('data.1.id', $archived->id)
        ->assertJsonPath('data.1.status', 'archived');
});

test('the dashboard tree can be filtered by status', function (): void {
    $archived = $this->inTenant(function (): Category {
        Category::factory()->create();

        return Category::factory()->archived()->create();
    });

    $this->tenantJson('GET', '/api/dashboard/categories?status=archived')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $archived->id);
});

test('an unknown status filter is rejected', function (): void {
    $this->tenantJson('GET', '/api/dashboard/categories?status=deleted')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['status']]]);
});

test('the tree never shows another tenant categories', function (): void {
    $this->inTenant(fn () => Category::factory()->create(), $this->otherTenant());

    $this->tenantJson('GET', '/api/dashboard/categories')->assertOk()->assertJsonCount(0, 'data');
});

test('an owner creates a root category with an arabic slug, audited', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/categories', ['name' => 'كتب إعدادي', 'sort_order' => 3])
        ->assertCreated()
        ->assertExactJsonStructure(['data' => DASHBOARD_CATEGORY_KEYS])
        ->assertJsonPath('data.name', 'كتب إعدادي')
        ->assertJsonPath('data.slug', 'كتب-إعدادي')
        ->assertJsonPath('data.parent_id', null)
        ->assertJsonPath('data.kind', 'general')
        ->assertJsonPath('data.icon', null)
        ->assertJsonPath('data.sort_order', 3)
        ->assertJsonPath('data.status', 'active');

    $id = $response->json('data.id');

    $this->assertDatabaseHas('categories', ['id' => $id, 'tenant_id' => $this->tenant->id, 'path' => "/{$id}/"]);
    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $this->tenant->id, 'action' => 'category.created', 'entity_id' => $id, 'actor_id' => $this->me->id,
    ]);
});

test('D68 an owner creates a root category of a given kind', function (): void {
    $this->tenantJson('POST', '/api/dashboard/categories', ['name' => 'ألعاب', 'kind' => 'toys'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'toys');

    $this->assertDatabaseHas('categories', ['tenant_id' => $this->tenant->id, 'name' => 'ألعاب', 'kind' => 'toys']);
});

test('D68 a sub-category inherits the kind of its parent and may send kind only as null', function (): void {
    $parent = $this->inTenant(fn () => Category::factory()->books()->create());

    $this->tenantJson('POST', '/api/dashboard/categories', ['name' => 'أولى إعدادي', 'parent_id' => $parent->id, 'kind' => null])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'books');
});

test('D68 a kind sent for a sub-category is rejected', function (): void {
    $parent = $this->inTenant(fn () => Category::factory()->books()->create());

    $this->tenantJson('POST', '/api/dashboard/categories', ['name' => 'x', 'parent_id' => $parent->id, 'kind' => 'toys'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.kind.0', 'القسم الفرعي يرث نوع القسم الرئيسي.');
});

test('BR-P06 a sub-category gets the parent path as prefix', function (): void {
    $parent = $this->inTenant(fn () => Category::factory()->create());

    $id = $this->tenantJson('POST', '/api/dashboard/categories', ['name' => 'أولى إعدادي', 'parent_id' => $parent->id])
        ->assertCreated()
        ->assertJsonPath('data.parent_id', $parent->id)
        ->json('data.id');

    $this->assertDatabaseHas('categories', ['id' => $id, 'path' => "/{$parent->id}/{$id}/"]);
});

test('a category can be created with an image stored under the tenant folder', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/categories', [
        'name' => 'أقلام',
        'image' => UploadedFile::fake()->image('pens.jpg'),
    ])->assertCreated();

    $path = Category::withoutTenantScope()->findOrFail($response->json('data.id'))->image_path;

    expect($path)->toStartWith("tenants/{$this->tenant->id}/categories/")
        ->and($response->json('data.image_url'))->toEndWith($path);
    Storage::disk('public')->assertExists($path);
});

test('category creation validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/categories', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing name' => [[], 'name'],
    'name too long' => [['name' => str_repeat('a', 101)], 'name'],
    'parent not an integer' => [['name' => 'x', 'parent_id' => 'abc'], 'parent_id'],
    'negative sort order' => [['name' => 'x', 'sort_order' => -1], 'sort_order'],
    'image is not an image' => [['name' => 'x', 'image' => 'not-a-file'], 'image'],
    'unknown kind' => [['name' => 'x', 'kind' => 'furniture'], 'kind'],
    'unknown icon' => [['name' => 'x', 'icon' => 'rocket'], 'icon'],
]);

test('BR-T04 a parent from another tenant is rejected as unknown', function (): void {
    $foreign = $this->inTenant(fn () => Category::factory()->create(), $this->otherTenant());

    $this->tenantJson('POST', '/api/dashboard/categories', ['name' => 'x', 'parent_id' => $foreign->id])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['parent_id']]]);

    $this->assertDatabaseMissing('categories', ['tenant_id' => $this->tenant->id]);
});

test('an owner renames a category, keeping its slug, audited with old and new values', function (): void {
    $category = $this->inTenant(fn () => Category::factory()->create(['name' => 'قديم']));
    $slug = $category->slug;

    $this->tenantJson('PUT', "/api/dashboard/categories/{$category->id}", ['name' => 'جديد', 'parent_id' => null])
        ->assertOk()
        ->assertJsonPath('data.name', 'جديد')
        ->assertJsonPath('data.slug', $slug);

    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $this->tenant->id, 'action' => 'category.updated', 'entity_id' => $category->id,
        'old_values' => json_encode(['name' => 'قديم']), 'new_values' => json_encode(['name' => 'جديد']),
    ]);
});

test('BR-P06 moving a category moves its whole subtree', function (): void {
    [$a, $b, $child, $grandchild] = $this->inTenant(function (): array {
        $a = Category::factory()->create();
        $b = Category::factory()->create();
        $child = Category::factory()->childOf($a)->create();

        return [$a, $b, $child, Category::factory()->childOf($child)->create()];
    });

    $this->tenantJson('PUT', "/api/dashboard/categories/{$child->id}", ['name' => $child->name, 'parent_id' => $b->id])
        ->assertOk()
        ->assertJsonPath('data.parent_id', $b->id);

    $this->assertDatabaseHas('categories', ['id' => $child->id, 'path' => "/{$b->id}/{$child->id}/"]);
    $this->assertDatabaseHas('categories', ['id' => $grandchild->id, 'path' => "/{$b->id}/{$child->id}/{$grandchild->id}/"]);
    $this->assertDatabaseHas('categories', ['id' => $a->id, 'path' => "/{$a->id}/"]);
});

test('D68 changing the kind of a root re-kinds its whole subtree, audited', function (): void {
    [$root, $child] = $this->inTenant(function (): array {
        $root = Category::factory()->books()->create();

        return [$root, Category::factory()->childOf($root)->create()];
    });

    $this->tenantJson('PUT', "/api/dashboard/categories/{$root->id}", ['name' => $root->name, 'parent_id' => null, 'kind' => 'stationery'])
        ->assertOk()
        ->assertJsonPath('data.kind', 'stationery');

    $this->assertDatabaseHas('categories', ['id' => $child->id, 'kind' => 'stationery']);
    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $this->tenant->id, 'action' => 'category.updated', 'entity_id' => $root->id,
        'old_values' => json_encode(['kind' => 'books']), 'new_values' => json_encode(['kind' => 'stationery']),
    ]);
});

test('D68 a subtree moved under a root of another kind takes that kind', function (): void {
    [$toys, $child, $grandchild] = $this->inTenant(function (): array {
        $books = Category::factory()->books()->create();
        $child = Category::factory()->childOf($books)->create();

        return [Category::factory()->kind(CategoryKind::Toys)->create(), $child, Category::factory()->childOf($child)->create()];
    });

    $this->tenantJson('PUT', "/api/dashboard/categories/{$child->id}", ['name' => $child->name, 'parent_id' => $toys->id])
        ->assertOk()
        ->assertJsonPath('data.kind', 'toys');

    $this->assertDatabaseHas('categories', ['id' => $grandchild->id, 'kind' => 'toys']);
});

test('BR-P06 a category cannot be moved under its own descendant', function (): void {
    [$root, $child] = $this->inTenant(function (): array {
        $root = Category::factory()->create();

        return [$root, Category::factory()->childOf($root)->create()];
    });

    $this->tenantJson('PUT', "/api/dashboard/categories/{$root->id}", ['name' => $root->name, 'parent_id' => $child->id])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'category_cycle');

    $this->assertDatabaseHas('categories', ['id' => $root->id, 'parent_id' => null, 'path' => "/{$root->id}/"]);
});

test('BR-P06 a category cannot be its own parent', function (): void {
    $category = $this->inTenant(fn () => Category::factory()->create());

    $this->tenantJson('PUT', "/api/dashboard/categories/{$category->id}", ['name' => 'x', 'parent_id' => $category->id])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'category_cycle');
});

test('category update validation', function (array $body, string $field): void {
    $category = $this->inTenant(fn () => Category::factory()->create());

    $this->tenantJson('PUT', "/api/dashboard/categories/{$category->id}", $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing name' => [['parent_id' => null], 'name'],
    'parent_id must be present' => [['name' => 'x'], 'parent_id'],
    'sort order not an integer' => [['name' => 'x', 'parent_id' => null, 'sort_order' => 'first'], 'sort_order'],
    'unknown kind' => [['name' => 'x', 'parent_id' => null, 'kind' => 'furniture'], 'kind'],
    'unknown icon' => [['name' => 'x', 'parent_id' => null, 'icon' => 'rocket'], 'icon'],
]);

test('D68 a kind sent while moving under a parent is rejected', function (): void {
    [$root, $other] = $this->inTenant(fn (): array => [Category::factory()->create(), Category::factory()->create()]);

    $this->tenantJson('PUT', "/api/dashboard/categories/{$other->id}", ['name' => 'x', 'parent_id' => $root->id, 'kind' => 'toys'])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['kind']]]);
});

test('BR-T04 updating a category of another tenant is 404 and untouched', function (): void {
    $foreign = $this->inTenant(fn () => Category::factory()->create(['name' => 'original']), $this->otherTenant());

    $this->tenantJson('PUT', "/api/dashboard/categories/{$foreign->id}", ['name' => 'hacked', 'parent_id' => null])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('categories', ['id' => $foreign->id, 'name' => 'original']);
});

test('BR-P06 a category with products can be archived and the products stay linked, audited', function (): void {
    [$category, $product] = $this->inTenant(function (): array {
        $category = Category::factory()->create();

        return [$category, Product::factory()->create(['category_id' => $category->id])];
    });

    $this->tenantJson('POST', "/api/dashboard/categories/{$category->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'archived');

    $this->assertDatabaseHas('products', ['id' => $product->id, 'category_id' => $category->id, 'status' => 'active']);
    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'category.archived', 'entity_id' => $category->id]);
});

test('an archived category is restored, audited', function (): void {
    $category = $this->inTenant(fn () => Category::factory()->archived()->create());

    $this->tenantJson('POST', "/api/dashboard/categories/{$category->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'category.restored', 'entity_id' => $category->id]);
});

test('BR-T04 archiving or restoring a category of another tenant is 404 and untouched', function (string $verb): void {
    $foreign = $this->inTenant(fn () => Category::factory()->create(['status' => ArchiveStatus::Active]), $this->otherTenant());

    $this->tenantJson('POST', "/api/dashboard/categories/{$foreign->id}/{$verb}")->assertNotFound();

    $this->assertDatabaseHas('categories', ['id' => $foreign->id, 'status' => 'active']);
})->with(['archive', 'restore']);

test('categories are reordered in one call and the tree is returned, audited once', function (): void {
    [$first, $second] = $this->inTenant(fn (): array => [
        Category::factory()->create(['sort_order' => 1]),
        Category::factory()->create(['sort_order' => 2]),
    ]);

    $this->tenantJson('POST', '/api/dashboard/categories/reorder', [
        ['id' => $first->id, 'sort_order' => 5],
        ['id' => $second->id, 'sort_order' => 0],
    ])
        ->assertOk()
        ->assertJsonPath('data.0.id', $second->id)
        ->assertJsonPath('data.1.id', $first->id);

    $this->assertDatabaseHas('categories', ['id' => $first->id, 'sort_order' => 5]);
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'category.reordered']);
});

test('reorder validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/categories/reorder', $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing id' => [[['sort_order' => 1]], '0.id'],
    'missing sort order' => [[['id' => 1]], '0.sort_order'],
    'duplicate ids' => [[['id' => 1, 'sort_order' => 1], ['id' => 1, 'sort_order' => 2]], '0.id'],
]);

test('BR-T04 reordering with a category of another tenant is rejected and nothing changes', function (): void {
    $mine = $this->inTenant(fn () => Category::factory()->create(['sort_order' => 1]));
    $foreign = $this->inTenant(fn () => Category::factory()->create(['sort_order' => 1]), $this->otherTenant());

    $this->tenantJson('POST', '/api/dashboard/categories/reorder', [
        ['id' => $mine->id, 'sort_order' => 9],
        ['id' => $foreign->id, 'sort_order' => 9],
    ])->assertUnprocessable();

    $this->assertDatabaseHas('categories', ['id' => $mine->id, 'sort_order' => 1]);
    $this->assertDatabaseHas('categories', ['id' => $foreign->id, 'sort_order' => 1]);
});

test('D73 an owner creates a category with an icon', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/categories', ['name' => 'أدوات دراسية', 'icon' => 'pencil'])
        ->assertCreated()
        ->assertJsonPath('data.icon', 'pencil');

    $this->assertDatabaseHas('categories', ['id' => $response->json('data.id'), 'icon' => 'pencil']);
});

test('D73 an owner changes the icon of a category, audited with old and new values', function (): void {
    $category = $this->inTenant(fn () => Category::factory()->create(['icon' => CategoryIcon::Pencil]));

    $this->tenantJson('PUT', "/api/dashboard/categories/{$category->id}", ['name' => $category->name, 'parent_id' => null, 'icon' => 'backpack'])
        ->assertOk()
        ->assertJsonPath('data.icon', 'backpack');

    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $this->tenant->id, 'action' => 'category.updated', 'entity_id' => $category->id,
        'old_values' => json_encode(['icon' => 'pencil']), 'new_values' => json_encode(['icon' => 'backpack']),
    ]);
});

test('D73 an update without an icon keeps it and an icon sent as null removes it', function (): void {
    $category = $this->inTenant(fn () => Category::factory()->create(['icon' => CategoryIcon::Pencil]));
    $url = "/api/dashboard/categories/{$category->id}";

    $this->tenantJson('PUT', $url, ['name' => 'جديد', 'parent_id' => null])->assertOk()->assertJsonPath('data.icon', 'pencil');
    $this->tenantJson('PUT', $url, ['name' => 'جديد', 'parent_id' => null, 'icon' => null])->assertOk()->assertJsonPath('data.icon', null);

    $this->assertDatabaseHas('categories', ['id' => $category->id, 'icon' => null]);
});
