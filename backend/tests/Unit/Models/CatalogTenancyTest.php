<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/*
| BelongsToTenant behaviour for every Catalog model (rules/01-testing.md §4 Models, BR-T03, BR-P01).
*/

dataset('catalog models', [
    'unit' => [Unit::class],
    'category' => [Category::class],
    'field definition' => [ProductFieldDefinition::class],
    'field option' => [ProductFieldOption::class],
    'product' => [Product::class],
    'product image' => [ProductImage::class],
    'product relation' => [ProductRelation::class],
    'sku' => [Sku::class],
]);

test('BR-P01 a model created in tenant context gets that tenant id', function (string $model): void {
    /** @var class-string<Model> $model */
    $row = $this->inTenant(fn () => $model::factory()->create());

    expect($row->tenant_id)->toBe($this->tenant->id);
})->with('catalog models');

test('BR-T03 creating a model without a tenant in context throws', function (string $model): void {
    $model::factory()->create();
})->with('catalog models')->throws(RuntimeException::class, 'without a tenant in context');

test('BR-T03 queries without a tenant in context return nothing', function (string $model): void {
    $this->inTenant(fn () => $model::factory()->create());
    app(TenantContext::class)->clear();

    expect($model::query()->count())->toBe(0);
})->with('catalog models');

test('BR-T03 queries in context never see another tenant', function (string $model): void {
    $this->inTenant(fn () => $model::factory()->create(), $this->otherTenant());

    expect($this->inTenant(fn () => $model::query()->count()))->toBe(0);
})->with('catalog models');
