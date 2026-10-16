<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;

test('BR-P06 the store tree shows active categories with nested children in the documented shape', function (): void {
    [$root, $child, $grandchild] = $this->inTenant(function (): array {
        $root = Category::factory()->create(['name' => 'إعدادي']);
        $child = Category::factory()->childOf($root)->create(['name' => 'أولى إعدادي']);

        return [$root, $child, Category::factory()->childOf($child)->create()];
    });

    $this->tenantJson('GET', '/api/store/categories')
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['*' => ['id', 'name', 'slug', 'kind', 'icon', 'image_url', 'children' => ['*' => ['id', 'name', 'slug', 'kind', 'icon', 'image_url', 'children']]]]])
        ->assertJsonPath('data.0.id', $root->id)
        ->assertJsonPath('data.0.slug', 'إعدادي')
        ->assertJsonPath('data.0.kind', 'general')
        ->assertJsonPath('data.0.children.0.id', $child->id)
        ->assertJsonPath('data.0.children.0.children.0.id', $grandchild->id);
});

test('BR-P06 an archived category hides its whole subtree from the store', function (): void {
    $visible = $this->inTenant(function (): Category {
        $archived = Category::factory()->archived()->create();
        Category::factory()->childOf($archived)->create();

        return Category::factory()->create();
    });

    $this->tenantJson('GET', '/api/store/categories')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visible->id);
});

test('BR-T05 the store never shows categories of another tenant', function (): void {
    $this->inTenant(fn () => Category::factory()->create(), $this->otherTenant());

    $this->tenantJson('GET', '/api/store/categories')->assertOk()->assertExactJson(['data' => []]);
});

test('the store tree needs no login', function (): void {
    $this->inTenant(fn () => Category::factory()->create());

    $this->tenantJson('GET', '/api/store/categories')->assertOk()->assertJsonCount(1, 'data');
});
