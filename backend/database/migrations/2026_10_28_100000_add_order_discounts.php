<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 08 (BR-PR08, BR-O08, D85): manual discounts. A line keeps the price before the discount (`list_unit_price`,
| backfilled from `unit_price`) and the discount given on it; `unit_price` stays the price actually charged, so
| `line_total = unit_price × quantity` and every reader keep working. The order keeps its own discount:
| `order_total = items_total − discount_amount`. Sales users may discount a line up to the store's cap (null = never).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('list_unit_price', 12, 2)->nullable();
            $table->string('manual_discount_type', 8)->nullable(); // amount | percent
            $table->decimal('manual_discount_value', 12, 2)->nullable();
        });

        DB::statement('UPDATE order_items SET list_unit_price = unit_price');
        DB::statement('ALTER TABLE order_items ALTER COLUMN list_unit_price SET NOT NULL');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_manual_discount_type_known CHECK (manual_discount_type IS NULL OR manual_discount_type IN ('amount', 'percent'))");
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_manual_discount_complete CHECK ((manual_discount_type IS NULL) = (manual_discount_value IS NULL))');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_manual_discount_positive CHECK (manual_discount_value IS NULL OR manual_discount_value > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_price_within_list CHECK (unit_price >= 0.01 AND unit_price <= list_unit_price)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_undiscounted_at_list CHECK (manual_discount_type IS NOT NULL OR unit_price = list_unit_price)');

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('discount_type', 8)->nullable(); // amount | percent
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_reason', 300)->nullable();
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_discount_type_known CHECK (discount_type IS NULL OR discount_type IN ('amount', 'percent'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_discount_complete CHECK ((discount_type IS NULL) = (discount_value IS NULL) AND (discount_type IS NULL) = (discount_reason IS NULL))');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_discount_amount_range CHECK (discount_amount >= 0 AND discount_amount <= items_total AND (discount_type IS NOT NULL OR discount_amount = 0))');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_after_discount CHECK (order_total = items_total - discount_amount)');

        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->decimal('max_discount_percent_sales', 5, 2)->nullable(); // null = sales users cannot discount
        });

        DB::statement('ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_max_discount_percent_sales_range CHECK (max_discount_percent_sales IS NULL OR (max_discount_percent_sales > 0 AND max_discount_percent_sales < 100))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenant_settings DROP CONSTRAINT tenant_settings_max_discount_percent_sales_range');

        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn('max_discount_percent_sales');
        });

        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_total_after_discount');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_discount_amount_range');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_discount_complete');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_discount_type_known');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_amount', 'discount_reason']);
        });

        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_undiscounted_at_list');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_unit_price_within_list');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_manual_discount_positive');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_manual_discount_complete');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_manual_discount_type_known');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['list_unit_price', 'manual_discount_type', 'manual_discount_value']);
        });
    }
};
