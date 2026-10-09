<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\UpdateCategory;
use App\Modules\Catalog\Data\CategoryData;
use App\Modules\Catalog\Exceptions\CategoryCycle;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('UpdateCategory renames without moving when the parent is unchanged', function (): void {
    $category = Category::factory()->create(['name' => 'قديم', 'sort_order' => 2]);

    $updated = app(UpdateCategory::class)->handle($category, new CategoryData('جديد', null));

    expect($updated->name)->toBe('جديد')
        ->and($updated->sort_order)->toBe(2)
        ->and($updated->path)->toBe("/{$category->id}/");
});

test('BR-P06 UpdateCategory moves a node back to the root', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $grandchild = Category::factory()->childOf($child)->create();

    app(UpdateCategory::class)->handle($child, new CategoryData($child->name, null));

    expect($child->path)->toBe("/{$child->id}/")
        ->and($grandchild->refresh()->path)->toBe("/{$child->id}/{$grandchild->id}/");
});

test('BR-P06 UpdateCategory refuses a cycle and changes nothing', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $grandchild = Category::factory()->childOf($child)->create();

    expect(fn () => app(UpdateCategory::class)->handle($root, new CategoryData('x', $grandchild->id)))
        ->toThrow(CategoryCycle::class);

    expect($root->refresh()->parent_id)->toBeNull()
        ->and($root->name)->not->toBe('x')
        ->and(AuditLog::query()->count())->toBe(0);
});

test('UpdateCategory rejects an unknown parent', function (): void {
    $category = Category::factory()->create();

    app(UpdateCategory::class)->handle($category, new CategoryData('x', 999_999));
})->throws(InvalidCatalogInput::class);

test('UpdateCategory audits old and new name and path', function (): void {
    $a = Category::factory()->create();
    $category = Category::factory()->create(['name' => 'قديم']);

    app(UpdateCategory::class)->handle($category, new CategoryData('جديد', $a->id));

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('category.updated')
        ->and($log->old_values)->toEqual(['name' => 'قديم', 'parent_id' => null, 'path' => "/{$category->id}/"])
        ->and($log->new_values)->toEqual(['name' => 'جديد', 'parent_id' => $a->id, 'path' => "/{$a->id}/{$category->id}/"]);
});
