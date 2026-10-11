<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Http\Resources\FieldDefinitionResource;
use App\Modules\Catalog\Http\Resources\FieldOptionResource;
use App\Modules\Catalog\Http\Resources\UnitResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('the category resource has the store key set and hides dashboard fields on the store', function (): void {
    $category = Category::factory()->create(['image_path' => 'tenants/1/categories/a.png'])->refresh();

    $data = rendered(new CategoryResource($category), storeRequest());

    expect(array_keys($data))->toBe(['id', 'name', 'slug', 'kind', 'image_url', 'children'])
        ->and($data['kind'])->toBe('general')
        ->and($data['image_url'])->toEndWith('/storage/tenants/1/categories/a.png')
        ->and($data['children'])->toBe([]);
});

test('the category resource adds parent, order and status on the dashboard', function (): void {
    $category = Category::factory()->archived()->books()->create(['sort_order' => 4])->refresh();

    $data = rendered(new CategoryResource($category), dashboardRequest());

    expect(array_keys($data))->toBe(['id', 'name', 'slug', 'kind', 'image_url', 'parent_id', 'sort_order', 'status', 'children'])
        ->and($data['kind'])->toBe('books')
        ->and($data['status'])->toBe('archived')
        ->and($data['sort_order'])->toBe(4);
});

test('the category resource nests loaded children recursively', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $child->setRelation('children', new Collection);
    $root->setRelation('children', new Collection([$child]));

    $data = rendered(new CategoryResource($root), storeRequest());

    expect($data['children'][0]['id'])->toBe($child->id)
        ->and($data['children'][0]['children'])->toBe([]);
});

test('the unit resource key set', function (): void {
    $unit = Unit::factory()->create(['name' => 'كرتونة']);

    expect(rendered(new UnitResource($unit), storeRequest()))->toBe(['id' => $unit->id, 'name' => 'كرتونة', 'status' => 'active']);
});

test('the field resource key set, options empty when not loaded', function (): void {
    $field = ProductFieldDefinition::factory()->select()->create()->refresh();

    $data = rendered(new FieldDefinitionResource($field), dashboardRequest());

    expect(array_keys($data))->toBe([
        'id', 'key', 'label', 'type', 'kind', 'is_required', 'show_on_storefront', 'is_filterable', 'filter_order', 'sort_order', 'status', 'options',
    ])
        ->and($data['type'])->toBe('select')
        ->and($data['kind'])->toBeNull()
        ->and($data['options'])->toBe([]);
});

test('the field resource includes loaded options with the option key set', function (): void {
    $field = ProductFieldDefinition::factory()->select()->create();
    $option = ProductFieldOption::factory()->for($field, 'definition')->create(['label' => 'الأضواء', 'sort_order' => 1]);

    $data = rendered(new FieldDefinitionResource($field->load('options')), dashboardRequest());

    expect($data['options'])->toBe([['id' => $option->id, 'label' => 'الأضواء', 'sort_order' => 1, 'status' => 'active']])
        ->and(rendered(new FieldOptionResource($option->refresh()), dashboardRequest()))->toBe($data['options'][0]);
});
