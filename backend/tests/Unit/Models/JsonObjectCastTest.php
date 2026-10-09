<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Casts\JsonObject;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('an empty custom fields map is stored as a json object, never a list', function (): void {
    $product = Product::factory()->create(['custom_fields' => []]);

    $type = DB::table('products')->where('id', $product->id)->value(DB::raw('jsonb_typeof(custom_fields)'));

    expect($type)->toBe('object')
        ->and($product->refresh()->custom_fields)->toBe([]);
});

test('arabic values are stored unescaped and read back as an associative array', function (): void {
    $product = Product::factory()->create(['custom_fields' => ['brand' => 'الأضواء', 'grade' => 3]]);

    expect(DB::table('products')->where('id', $product->id)->value('custom_fields'))->toContain('الأضواء')
        ->and($product->refresh()->custom_fields)->toEqual(['brand' => 'الأضواء', 'grade' => 3]);
});

test('anything that is not a map reads and writes as an empty object', function (mixed $value): void {
    $cast = new JsonObject;
    $product = new Product;

    expect($cast->set($product, 'custom_fields', $value, []))->toBe('{}')
        ->and($cast->get($product, 'custom_fields', null, []))->toBe([])
        ->and($cast->get($product, 'custom_fields', '"text"', []))->toBe([]);
})->with(['null' => [null], 'string' => ['x'], 'empty list' => [[]]]);
