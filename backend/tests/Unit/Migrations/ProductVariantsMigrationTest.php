<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 14 (BR-P14, D92): the variants migration on a catalog of the Task 13 shape. It is rolled back first, inside
| the test's transaction (PostgreSQL DDL is transactional), SKUs are written with plain inserts, then it runs forward
| (and back) and the data is checked. Nothing outlives the test.
*/

function productVariantsMigration(): Migration
{
    $migration = require database_path('migrations/2026_11_03_100000_add_product_variants.php');

    return $migration instanceof Migration ? $migration : throw new RuntimeException('not a migration');
}

/**
 * @param  array<string, mixed>  $values
 */
function productVariantsInsert(string $table, array $values): int
{
    $id = DB::table($table)->insertGetId([...$values, 'created_at' => now(), 'updated_at' => now()]);

    return is_int($id) ? $id : (int) $id;
}

beforeEach(function (): void {
    productVariantsMigration()->down();

    $tenant = $this->tenant->id;
    $piece = productVariantsInsert('units', ['tenant_id' => $tenant, 'name' => 'قطعة']);
    $category = productVariantsInsert('categories', ['tenant_id' => $tenant, 'name' => 'أدوات', 'slug' => 'adawat', 'path' => '/', 'section_id' => $this->sectionId('stationery')]);
    $first = productVariantsInsert('products', ['tenant_id' => $tenant, 'category_id' => $category, 'name' => 'قلم', 'slug' => 'qalam', 'custom_fields' => '{}']);
    $second = productVariantsInsert('products', ['tenant_id' => $tenant, 'category_id' => $category, 'name' => 'كشكول', 'slug' => 'kashkol', 'custom_fields' => '{}']);

    $this->skus = [
        productVariantsInsert('skus', ['tenant_id' => $tenant, 'product_id' => $first, 'code' => 'PEN-1', 'base_unit_id' => $piece]),
        productVariantsInsert('skus', ['tenant_id' => $tenant, 'product_id' => $second, 'code' => 'NB-1', 'base_unit_id' => $piece]),
        productVariantsInsert('skus', ['tenant_id' => $tenant, 'product_id' => $first, 'code' => 'PEN-2', 'base_unit_id' => $piece]),
    ];
});

test('BR-P14 every existing sku becomes active, ordered by id within its product', function (): void {
    productVariantsMigration()->up();

    expect(DB::table('skus')->whereIn('id', $this->skus)->orderBy('id')->get(['code', 'status', 'sort_order'])->map(fn (object $row): array => (array) $row)->all())->toBe([
        ['code' => 'PEN-1', 'status' => 'active', 'sort_order' => 0],
        ['code' => 'NB-1', 'status' => 'active', 'sort_order' => 0],
        ['code' => 'PEN-2', 'status' => 'active', 'sort_order' => 1],
    ]);
});

test('BR-P14 the option tables exist and a sku status outside active and inactive is refused', function (): void {
    productVariantsMigration()->up();

    expect(Schema::hasTable('product_options'))->toBeTrue()
        ->and(Schema::hasTable('product_option_values'))->toBeTrue()
        ->and(Schema::hasTable('sku_option_values'))->toBeTrue()
        ->and(fn () => DB::table('skus')->where('id', $this->skus[0])->update(['status' => 'archived']))->toThrow(Illuminate\Database\QueryException::class);
});

test('the migration rolls back to the task 13 shape', function (): void {
    productVariantsMigration()->up();

    productVariantsMigration()->down();

    expect(Schema::hasTable('product_options'))->toBeFalse()
        ->and(Schema::hasTable('sku_option_values'))->toBeFalse()
        ->and(Schema::hasColumns('skus', ['status']))->toBeFalse()
        ->and(Schema::hasColumns('skus', ['sort_order']))->toBeFalse()
        ->and(DB::table('skus')->whereIn('id', $this->skus)->count())->toBe(3);
});
