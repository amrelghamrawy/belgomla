<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 10 (BR-P04, BR-O14, D88): pre-orders on Coming Soon products. A product opts in (`preorder_enabled`, an
| expected date, an optional cap on the total pre-ordered quantity). A pre-order is an order of `kind = preorder`: it
| stays pending, its lines reserve nothing (`reserved = false`) and are converted into normal orders when stock arrives
| (`converted_quantity`; the normal order points back with `converted_from_id`; `converted_at` closes a pre-order with
| nothing left). The cart keeps pre-order lines apart from order lines (`kind`).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('preorder_enabled')->default(false);
            $table->date('expected_at')->nullable();
            $table->integer('preorder_limit')->nullable(); // selling units over every open pre-order; null = no cap
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_preorder_limit_positive CHECK (preorder_limit IS NULL OR preorder_limit >= 1)');

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('kind', 10)->default('order');
            $table->foreignId('converted_from_id')->nullable()->constrained('orders'); // the pre-order a normal order came from
            $table->timestamp('converted_at')->nullable(); // a pre-order with nothing left to convert

            $table->index('converted_from_id');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_kind_known CHECK (kind IN ('order', 'preorder'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_converted_from_order CHECK (converted_from_id IS NULL OR kind = 'order')");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_converted_at_preorder CHECK (converted_at IS NULL OR kind = 'preorder')");
        DB::statement('CREATE INDEX orders_tenant_kind_status_placed_index ON orders (tenant_id, kind, status, placed_at)');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->boolean('reserved')->default(true); // false on pre-order lines: they hold no stock (BR-INV01)
            $table->integer('converted_quantity')->default(0); // selling units of a pre-order line already in a normal order
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_converted_within_ordered CHECK (converted_quantity >= 0 AND converted_quantity <= quantity)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_converted_unreserved CHECK (converted_quantity = 0 OR NOT reserved)');

        DB::statement('ALTER TABLE order_item_changes DROP CONSTRAINT order_item_changes_change_known');
        DB::statement("ALTER TABLE order_item_changes ADD CONSTRAINT order_item_changes_change_known CHECK (change IN ('added', 'removed', 'quantity', 'delivered_short', 'converted'))");

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->string('kind', 10)->default('order');
            $table->dropUnique(['cart_id', 'sku_id']);
            $table->unique(['cart_id', 'sku_id', 'kind']);
        });

        DB::statement("ALTER TABLE cart_items ADD CONSTRAINT cart_items_kind_known CHECK (kind IN ('order', 'preorder'))");

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_known');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_known CHECK (type IN ('new_order', 'customer_cancelled', 'whatsapp_dropped', 'order_expired', 'new_preorder'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM notifications WHERE type = 'new_preorder'");
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_known');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_known CHECK (type IN ('new_order', 'customer_cancelled', 'whatsapp_dropped', 'order_expired'))");

        DB::statement("DELETE FROM cart_items WHERE kind = 'preorder'");
        DB::statement('ALTER TABLE cart_items DROP CONSTRAINT cart_items_kind_known');

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique(['cart_id', 'sku_id', 'kind']);
            $table->unique(['cart_id', 'sku_id']);
            $table->dropColumn('kind');
        });

        DB::statement("DELETE FROM order_item_changes WHERE change = 'converted'");
        DB::statement('ALTER TABLE order_item_changes DROP CONSTRAINT order_item_changes_change_known');
        DB::statement("ALTER TABLE order_item_changes ADD CONSTRAINT order_item_changes_change_known CHECK (change IN ('added', 'removed', 'quantity', 'delivered_short'))");

        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_converted_unreserved');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_converted_within_ordered');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['reserved', 'converted_quantity']);
        });

        DB::statement('DROP INDEX orders_tenant_kind_status_placed_index');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_converted_at_preorder');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_converted_from_order');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_kind_known');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['converted_from_id']);
            $table->dropIndex(['converted_from_id']);
            $table->dropColumn(['kind', 'converted_from_id', 'converted_at']);
        });

        DB::statement('ALTER TABLE products DROP CONSTRAINT products_preorder_limit_positive');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['preorder_enabled', 'expected_at', 'preorder_limit']);
        });
    }
};
