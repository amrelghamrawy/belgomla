<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Queries\CategoryTreeQuery;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('roots and children are ordered by sort order then id', function (): void {
    $second = Category::factory()->create(['sort_order' => 2]);
    $first = Category::factory()->create(['sort_order' => 1]);
    $childB = Category::factory()->childOf($first)->create(['sort_order' => 5]);
    $childA = Category::factory()->childOf($first)->create(['sort_order' => 5]);

    $roots = app(CategoryTreeQuery::class)->forDashboard();

    expect($roots->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($roots->first()->children->pluck('id')->all())->toBe([$childB->id, $childA->id])
        ->and($roots->last()->children)->toBeEmpty();
});

test('the dashboard keeps a filtered child whose parent is filtered out as a root', function (): void {
    $parent = Category::factory()->create();
    $archivedChild = Category::factory()->childOf($parent)->archived()->create();

    $roots = app(CategoryTreeQuery::class)->forDashboard(ArchiveStatus::Archived);

    expect($roots->pluck('id')->all())->toBe([$archivedChild->id]);
});

test('BR-P06 the store prunes archived categories with their subtree', function (): void {
    $kept = Category::factory()->create();
    $archived = Category::factory()->archived()->create();
    $orphan = Category::factory()->childOf($archived)->create();
    Category::factory()->childOf($orphan)->create();

    $roots = app(CategoryTreeQuery::class)->forStore();

    expect($roots->pluck('id')->all())->toBe([$kept->id]);
});

test('the tree is built in a single query', function (): void {
    $root = Category::factory()->create();
    Category::factory()->childOf(Category::factory()->childOf($root)->create())->create();

    Illuminate\Support\Facades\DB::enableQueryLog();
    app(CategoryTreeQuery::class)->forStore()->first()->children->first()->children->first()->children->all();

    expect(Illuminate\Support\Facades\DB::getQueryLog())->toHaveCount(1);
});
