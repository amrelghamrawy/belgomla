<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 15 (BR-P15, D93): the bundles migration on a catalog of the Task 14 shape. It is rolled back first, inside the
| test's transaction (PostgreSQL DDL is transactional), a product is written with a plain insert, then it runs forward
| (and back). Nothing outlives the test.
*/

function bundlesMigration(): Migration
{
    $migration = require database_path('migrations/2026_11_04_100000_add_bundles.php');

    return $migration instanceof Migration ? $migration : throw new RuntimeException('not a migration');
}

beforeEach(function (): void {
    bundlesMigration()->down();

    $category = DB::table('categories')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'كتب', 'slug' => 'kotob', 'path' => '/', 'created_at' => now(), 'updated_at' => now()]);
    $this->product = DB::table('products')->insertGetId(['tenant_id' => $this->tenant->id, 'category_id' => $category, 'name' => 'علوم', 'slug' => 'oloom', 'custom_fields' => '{}', 'created_at' => now(), 'updated_at' => now()]);
});

test('BR-P15 every existing product becomes simple', function (): void {
    bundlesMigration()->up();

    expect(DB::table('products')->where('id', $this->product)->value('type'))->toBe('simple');
});

test('BR-P15 the bundle tables and order line columns exist, and a product type outside simple and bundle is refused', function (): void {
    bundlesMigration()->up();

    expect(Schema::hasTable('bundle_items'))->toBeTrue()
        ->and(Schema::hasTable('bundle_pricing'))->toBeTrue()
        ->and(Schema::hasColumns('order_items', ['parent_item_id', 'is_bundle']))->toBeTrue()
        ->and(fn () => DB::table('products')->where('id', $this->product)->update(['type' => 'kit']))->toThrow(Illuminate\Database\QueryException::class);
});

test('the migration rolls back to the task 14 shape', function (): void {
    bundlesMigration()->up();

    bundlesMigration()->down();

    expect(Schema::hasTable('bundle_items'))->toBeFalse()
        ->and(Schema::hasTable('bundle_pricing'))->toBeFalse()
        ->and(Schema::hasColumns('order_items', ['parent_item_id']))->toBeFalse()
        ->and(Schema::hasColumns('order_items', ['is_bundle']))->toBeFalse()
        ->and(Schema::hasColumns('products', ['type']))->toBeFalse()
        ->and(DB::table('products')->where('id', $this->product)->exists())->toBeTrue();
});
