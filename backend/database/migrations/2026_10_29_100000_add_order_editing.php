<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 09 (BR-O06, BR-O12, BR-O13, D86, D87): the merchant edits the lines of a pending or confirmed order, and
| records a delivery short of what was ordered. A removed line is kept for history (`removed_at`) and left out of every
| total; a delivered line says how much went out (`delivered_quantity`, backfilled for orders delivered before: they
| went out in full). Every line change is kept in `order_item_changes`. Customers hear about edits by default.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->integer('delivered_quantity')->nullable(); // selling units; null until delivered (BR-O13)
            $table->timestamp('removed_at')->nullable(); // the merchant removed the line (BR-O12)
        });

        DB::statement("UPDATE order_items SET delivered_quantity = quantity WHERE order_id IN (SELECT id FROM orders WHERE status = 'delivered')");
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_delivered_within_ordered CHECK (delivered_quantity IS NULL OR (delivered_quantity >= 0 AND delivered_quantity <= quantity))');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_removed_not_delivered CHECK (removed_at IS NULL OR delivered_quantity IS NULL)');

        Schema::create('order_item_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('order_item_id')->nullable()->constrained('order_items');
            $table->string('change', 16); // added | removed | quantity | delivered_short
            $table->integer('old_quantity')->nullable();
            $table->integer('new_quantity')->nullable();
            $table->decimal('unit_price_before', 12, 2)->nullable();
            $table->decimal('unit_price_after', 12, 2)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('tenant_users'); // null = system
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'id']);
        });

        DB::statement("ALTER TABLE order_item_changes ADD CONSTRAINT order_item_changes_change_known CHECK (change IN ('added', 'removed', 'quantity', 'delivered_short'))");

        DB::statement('ALTER TABLE tenant_settings ALTER COLUMN order_notify_statuses SET DEFAULT \'["confirmed", "out_for_delivery", "delivered", "cancelled", "edited"]\'::jsonb');
        // D87: on by default, also for stores that already notify something; a store that turned every message off stays off.
        DB::statement("UPDATE tenant_settings SET order_notify_statuses = order_notify_statuses || '[\"edited\"]'::jsonb WHERE jsonb_array_length(order_notify_statuses) > 0 AND NOT order_notify_statuses @> '[\"edited\"]'::jsonb");
    }

    public function down(): void
    {
        DB::statement("UPDATE tenant_settings SET order_notify_statuses = order_notify_statuses - 'edited'");
        DB::statement('ALTER TABLE tenant_settings ALTER COLUMN order_notify_statuses SET DEFAULT \'["confirmed", "out_for_delivery", "delivered", "cancelled"]\'::jsonb');

        Schema::drop('order_item_changes');

        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_removed_not_delivered');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_delivered_within_ordered');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['delivered_quantity', 'removed_at']);
        });
    }
};
