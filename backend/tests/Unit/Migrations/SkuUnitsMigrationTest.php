<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 13 (D91): the two sku_units migrations on a catalog in the V1 shape. Both are rolled back first, inside the
| test's transaction (PostgreSQL DDL is transactional), a V1 catalog is written with plain inserts, then they run
| forward (and back) and the data is checked. Nothing outlives the test.
*/

function skuUnitsMigration(string $name): Migration
{
    $migration = require database_path("migrations/{$name}.php");

    return $migration instanceof Migration ? $migration : throw new RuntimeException("{$name} is not a migration.");
}

function skuUnitsStepOne(): Migration
{
    return skuUnitsMigration('2026_11_02_100000_add_sku_units');
}

function skuUnitsStepTwo(): Migration
{
    return skuUnitsMigration('2026_11_02_100100_drop_sku_pricing_columns');
}

/**
 * @param  array<string, mixed>  $values
 */
function skuUnitsInsert(string $table, array $values): int
{
    $id = DB::table($table)->insertGetId([...$values, 'created_at' => now(), 'updated_at' => now()]);

    return is_int($id) ? $id : (int) $id;
}

beforeEach(function (): void {
    (require database_path('migrations/2026_11_04_100000_add_bundles.php'))->down(); // V2 Task 15: bundle_items points at sku_units
    skuUnitsStepTwo()->down();
    skuUnitsStepOne()->down();

    $tenant = $this->tenant->id;
    $piece = skuUnitsInsert('units', ['tenant_id' => $tenant, 'name' => 'قطعة']);
    $carton = skuUnitsInsert('units', ['tenant_id' => $tenant, 'name' => 'كرتونة']);
    $category = skuUnitsInsert('categories', ['tenant_id' => $tenant, 'name' => 'كتب', 'slug' => 'books', 'path' => '/', 'section_id' => $this->sectionId('books')]);
    $product = skuUnitsInsert('products', ['tenant_id' => $tenant, 'category_id' => $category, 'name' => 'الأضواء علوم', 'slug' => 'adwaa', 'custom_fields' => '{}']);

    $this->sku = skuUnitsInsert('skus', [
        'tenant_id' => $tenant, 'product_id' => $product, 'code' => 'ADW-1', 'selling_unit_id' => $carton, 'base_unit_id' => $piece,
        'conversion_factor' => 20, 'cover_price' => '120.00', 'base_price' => '90.00', 'base_price_percent' => '25.00', 'moq' => 5,
    ]);
    $this->plain = skuUnitsInsert('skus', [
        'tenant_id' => $tenant, 'product_id' => $product, 'code' => 'ADW-2', 'selling_unit_id' => $piece, 'base_unit_id' => $piece,
        'conversion_factor' => 1, 'cover_price' => null, 'base_price' => '10.00', 'base_price_percent' => null, 'moq' => 1,
    ]);
    $this->tier = skuUnitsInsert('sku_price_tiers', ['tenant_id' => $tenant, 'sku_id' => $this->sku, 'min_quantity' => 10, 'unit_price' => '84.00', 'discount_percent' => '30.00']);
    [$this->carton, $this->piece] = [$carton, $piece];

    $customer = $this->makeCustomer();
    $cart = skuUnitsInsert('carts', ['tenant_id' => $tenant, 'customer_id' => $customer->id]);
    $this->cartItem = skuUnitsInsert('cart_items', ['tenant_id' => $tenant, 'cart_id' => $cart, 'sku_id' => $this->sku, 'quantity' => 6]);
    $this->price = skuUnitsInsert('customer_sku_prices', ['tenant_id' => $tenant, 'customer_id' => $customer->id, 'sku_id' => $this->sku, 'unit_price' => '80.00']);
    $order = $this->inTenant(fn (): Order => Order::factory()->for(Customer::query()->findOrFail($customer->id))->create());
    $this->orderItem = skuUnitsInsert('order_items', [
        'tenant_id' => $tenant, 'order_id' => $order->id, 'sku_id' => $this->sku, 'product_id' => $product, 'product_name' => 'الأضواء علوم',
        'sku_code' => 'ADW-1', 'sku_name' => 'الأضواء علوم', 'unit_name' => 'كرتونة', 'conversion_factor' => 20, 'quantity' => 6,
        'quantity_base' => 120, 'unit_price' => '90.00', 'list_unit_price' => '90.00', 'line_total' => '540.00',
    ]);
});

test('D91 step one gives every SKU one default unit with its own price, %, cover price, MOQ and factor', function (): void {
    skuUnitsStepOne()->up();

    $units = DB::table('sku_units')->orderBy('sku_id')->get(['sku_id', 'unit_id', 'conversion_factor', 'base_price', 'base_price_percent', 'cover_price', 'moq', 'is_default', 'status'])
        ->map(fn (object $unit): array => (array) $unit)->all();

    expect($units)->toBe([
        ['sku_id' => $this->sku, 'unit_id' => $this->carton, 'conversion_factor' => 20, 'base_price' => '90.00', 'base_price_percent' => '25.00', 'cover_price' => '120.00', 'moq' => 5, 'is_default' => true, 'status' => 'active'],
        ['sku_id' => $this->plain, 'unit_id' => $this->piece, 'conversion_factor' => 1, 'base_price' => '10.00', 'base_price_percent' => null, 'cover_price' => null, 'moq' => 1, 'is_default' => true, 'status' => 'active'],
    ]);
});

test('D91 step one moves the tiers to the default unit and points cart lines, order lines and negotiated prices at it', function (): void {
    skuUnitsStepOne()->up();

    $unit = DB::table('sku_units')->where('sku_id', $this->sku)->value('id');

    expect(DB::table('sku_price_tiers')->where('id', $this->tier)->first(['sku_unit_id', 'min_quantity', 'unit_price', 'discount_percent']))
        ->toEqual((object) ['sku_unit_id' => $unit, 'min_quantity' => 10, 'unit_price' => '84.00', 'discount_percent' => '30.00'])
        ->and(DB::table('cart_items')->where('id', $this->cartItem)->value('sku_unit_id'))->toBe($unit)
        ->and(DB::table('order_items')->where('id', $this->orderItem)->value('sku_unit_id'))->toBe($unit)
        ->and(DB::table('customer_sku_prices')->where('id', $this->price)->value('sku_unit_id'))->toBe($unit);
});

test('D91 step one keeps the SKU columns, nullable, so it can be rolled back', function (): void {
    skuUnitsStepOne()->up();

    expect(Schema::hasColumns('skus', ['selling_unit_id', 'conversion_factor', 'cover_price', 'base_price', 'base_price_percent', 'moq']))->toBeTrue()
        ->and(DB::table('skus')->where('id', $this->sku)->value('base_price'))->toBe('90.00');
});

test('D91 step two drops the SKU price columns and the tiers\' sku_id', function (): void {
    skuUnitsStepOne()->up();
    skuUnitsStepTwo()->up();

    expect(Schema::hasColumns('skus', ['selling_unit_id']))->toBeFalse()
        ->and(Schema::hasColumns('skus', ['conversion_factor']))->toBeFalse()
        ->and(Schema::hasColumns('skus', ['cover_price']))->toBeFalse()
        ->and(Schema::hasColumns('skus', ['base_price']))->toBeFalse()
        ->and(Schema::hasColumns('skus', ['base_price_percent']))->toBeFalse()
        ->and(Schema::hasColumns('skus', ['moq']))->toBeFalse()
        ->and(Schema::hasColumn('sku_price_tiers', 'sku_id'))->toBeFalse()
        ->and(Schema::hasColumns('skus', ['code', 'base_unit_id']))->toBeTrue();
});

test('D91 rolling back step two puts the SKU columns back filled from the default unit', function (): void {
    skuUnitsStepOne()->up();
    skuUnitsStepTwo()->up();
    DB::table('sku_units')->where('sku_id', $this->sku)->update(['base_price' => '95.00', 'moq' => 3]);

    skuUnitsStepTwo()->down();

    expect((array) DB::table('skus')->where('id', $this->sku)->first(['selling_unit_id', 'conversion_factor', 'base_price', 'base_price_percent', 'cover_price', 'moq']))
        ->toBe(['selling_unit_id' => $this->carton, 'conversion_factor' => 20, 'base_price' => '95.00', 'base_price_percent' => '25.00', 'cover_price' => '120.00', 'moq' => 3])
        ->and(DB::table('sku_price_tiers')->where('id', $this->tier)->value('sku_id'))->toBe($this->sku);
});

test('D91 rolling back both steps leaves the V1 shape with the default unit\'s data and drops what other units held', function (): void {
    skuUnitsStepOne()->up();
    $other = skuUnitsInsert('sku_units', ['tenant_id' => $this->tenant->id, 'sku_id' => $this->sku, 'unit_id' => $this->piece, 'conversion_factor' => 1, 'base_price' => '5.00', 'moq' => 1, 'is_default' => false]);
    skuUnitsInsert('sku_price_tiers', ['tenant_id' => $this->tenant->id, 'sku_unit_id' => $other, 'min_quantity' => 10, 'unit_price' => '4.00']);

    skuUnitsStepOne()->down();

    expect(Schema::hasTable('sku_units'))->toBeFalse()
        ->and(DB::table('sku_price_tiers')->pluck('id')->all())->toBe([$this->tier])
        ->and(DB::table('skus')->where('id', $this->sku)->value('base_price'))->toBe('90.00')
        ->and(DB::table('cart_items')->where('id', $this->cartItem)->value('sku_id'))->toBe($this->sku);
});
