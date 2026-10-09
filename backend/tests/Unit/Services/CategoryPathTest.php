<?php

declare(strict_types=1);

use App\Modules\Catalog\Exceptions\CategoryCycle;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryPath;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->paths = app(CategoryPath::class);
});

test('the path under no parent is a root path', function (): void {
    $category = Category::factory()->create();

    expect($this->paths->under($category, null))->toBe("/{$category->id}/");
});

test('the path under a parent extends the parent path', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $other = Category::factory()->create();

    expect($this->paths->under($other, $child))->toBe("/{$root->id}/{$child->id}/{$other->id}/");
});

test('BR-P06 a category cannot go under itself or a descendant', function (string $target): void {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $grandchild = Category::factory()->childOf($child)->create();

    $this->paths->under($root, ['self' => $root, 'child' => $child, 'grandchild' => $grandchild][$target]);
})->with(['self', 'child', 'grandchild'])->throws(CategoryCycle::class);

test('isWithin does not confuse ids that share digits', function (): void {
    $a = Category::factory()->create();
    $b = Category::factory()->create();
    $b->path = "/1{$a->id}/";

    expect($this->paths->isWithin($b, $a))->toBeFalse();
});

test('ancestor ids run from the root down and exclude the category', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $grandchild = Category::factory()->childOf($child)->create()->refresh();

    expect($this->paths->ancestorIds($grandchild))->toBe([$root->id, $child->id])
        ->and($this->paths->ancestorIds($root->refresh()))->toBe([]);
});
