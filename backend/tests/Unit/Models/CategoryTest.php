<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\CategoryIcon;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Scopes\Active;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $category = $this->inTenant(fn () => Category::factory()->create()->fresh());

    expect(array_keys($category->toArray()))->toBe([
        'id', 'tenant_id', 'parent_id', 'name', 'slug', 'path', 'sort_order', 'image_path', 'status', 'created_at', 'updated_at', 'icon', 'section_id', 'section',
    ]);
});

test('casts', function (): void {
    $category = $this->inTenant(fn () => Category::factory()->archived()->books()->create(['sort_order' => '3', 'icon' => 'backpack'])->fresh());

    expect($category->status)->toBe(ArchiveStatus::Archived)
        ->and($category->section->key)->toBe('books')
        ->and($category->icon)->toBe(CategoryIcon::Backpack)
        ->and($category->sort_order)->toBe(3);
});

test('D109 c a root category goes to the fallback section «عام» unless a section is given', function (): void {
    $category = $this->inTenant(fn () => Category::query()->create(['name' => 'أقلام'])->fresh());

    expect($category->section->name)->toBe('عام')
        ->and($category->section->is_fallback)->toBeTrue();
});

test('D68 a child category takes the kind of its parent, whatever was given', function (): void {
    [$child, $grandchild] = $this->inTenant(function (): array {
        $root = Category::factory()->books()->create();
        $child = Category::factory()->childOf($root)->inSection('toys')->create();

        return [$child->fresh(), Category::factory()->childOf($child)->create()->fresh()];
    });

    expect($child->section->key)->toBe('books')
        ->and($grandchild->section->key)->toBe('books');
});

test('D109 the database refuses a category without a section', function (): void {
    $category = $this->inTenant(fn () => Category::factory()->create());

    $this->inTenant(fn () => Category::query()->whereKey($category->id)->update(['section_id' => null]));
})->throws(QueryException::class, 'section_id');

test('D109 the database refuses a section that does not exist', function (): void {
    $category = $this->inTenant(fn () => Category::factory()->create());

    $this->inTenant(fn () => Category::query()->whereKey($category->id)->update(['section_id' => 999_999]));
})->throws(QueryException::class, 'categories_section_id_foreign');

test('a root category gets a slug from its arabic name and a path with its own id', function (): void {
    $category = $this->inTenant(fn () => Category::query()->create(['name' => 'كتب إعدادي'])->fresh());

    expect($category->slug)->toBe('كتب-إعدادي')
        ->and($category->path)->toBe("/{$category->id}/");
});

test('a child category path extends its parent path', function (): void {
    [$root, $child, $grandchild] = $this->inTenant(function (): array {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();

        return [$root, $child, Category::factory()->childOf($child)->create()->fresh()];
    });

    expect($grandchild->path)->toBe("/{$root->id}/{$child->id}/{$grandchild->id}/");
});

test('relations: parent, children and products', function (): void {
    [$root, $child, $product] = $this->inTenant(function (): array {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();

        return [$root, $child, Product::factory()->create(['category_id' => $root->id])];
    });

    expect($child->parent->is($root))->toBeTrue()
        ->and($root->children->pluck('id')->all())->toBe([$child->id])
        ->and($root->products->pluck('id')->all())->toBe([$product->id]);
});

test('the image url is null without an image and a public url with one', function (): void {
    [$plain, $withImage] = $this->inTenant(fn (): array => [
        Category::factory()->create(),
        Category::factory()->create(['image_path' => 'tenants/1/categories/a.jpg']),
    ]);

    expect($plain->imageUrl())->toBeNull()
        ->and($withImage->imageUrl())->toEndWith('/storage/tenants/1/categories/a.jpg');
});

test('the Active scope keeps only active categories', function (): void {
    $active = $this->inTenant(function (): Category {
        Category::factory()->archived()->create();

        return Category::factory()->create();
    });

    $ids = $this->inTenant(fn () => Category::query()->tap(new Active)->pluck('id')->all());

    expect($ids)->toBe([$active->id]);
});

test('BR-P06 the database refuses a category that is its own parent', function (): void {
    $category = $this->inTenant(fn () => Category::factory()->create());

    $this->inTenant(fn () => $category->forceFill(['parent_id' => $category->id])->saveQuietly());
})->throws(QueryException::class, 'categories_parent_not_self');
