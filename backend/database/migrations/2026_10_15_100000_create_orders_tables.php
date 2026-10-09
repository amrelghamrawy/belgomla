<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-O09: the next order number of each tenant, incremented under row lock inside checkout.
        Schema::create('tenant_order_counters', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            $table->integer('next_number')->default(1);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->integer('number'); // per-tenant sequence (BR-O09)
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('status', 30);
            $table->decimal('items_total', 12, 2);
            $table->decimal('order_total', 12, 2); // = items_total in V1 (BR-O08)
            $table->decimal('collected_amount', 12, 2)->default(0); // Σ non-voided collections (Task 7)
            $table->string('collection_status', 30)->default('unpaid'); // derived (BR-C03)
            $table->string('delivery_name', 150); // snapshots (BR-O10)
            $table->string('delivery_phone', 20);
            $table->jsonb('delivery_address');
            $table->text('customer_note')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->string('cancelled_by_type', 20)->nullable();
            $table->timestamp('placed_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'collection_status']);
        });

        DB::statement('CREATE INDEX orders_tenant_status_placed_index ON orders (tenant_id, status, placed_at DESC)');
        DB::statement('CREATE INDEX orders_tenant_customer_placed_index ON orders (tenant_id, customer_id, placed_at DESC)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_known CHECK (status IN ('pending', 'confirmed', 'preparing', 'out_for_delivery', 'delivered', 'cancelled'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_collection_status_known CHECK (collection_status IN ('unpaid', 'partially_collected', 'collected'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_cancelled_by_known CHECK (cancelled_by_type IS NULL OR cancelled_by_type IN ('customer', 'tenant_user'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_number_positive CHECK (number >= 1)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_collected_non_negative CHECK (collected_amount >= 0)');

        // BR-PR07: everything shown on an order line is a snapshot; sku_id/product_id are references only.
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained('skus');
            $table->foreignId('product_id')->constrained('products');
            $table->string('product_name', 200);
            $table->string('sku_code', 60);
            $table->string('sku_name', 200);
            $table->string('unit_name', 50);
            $table->integer('conversion_factor');
            $table->integer('quantity'); // selling units
            $table->integer('quantity_base'); // = quantity × conversion_factor, what is reserved (BR-INV03)
            $table->decimal('cover_price', 12, 2)->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->integer('tier_min_quantity')->nullable();
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index(['order_id', 'id']);
            $table->index(['tenant_id', 'sku_id']);
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity >= 1)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_base CHECK (quantity_base = quantity * conversion_factor)');

        // BR-O04: every status change, append-only.
        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'id']);
        });

        DB::statement("ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_actor_known CHECK (actor_type IN ('tenant_user', 'customer', 'system'))");

        // BR-N02: merchant in-app notifications, our own table (not Laravel's). A null user means every owner.
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tenant_user_id')->nullable()->constrained('tenant_users')->cascadeOnDelete();
            $table->string('type', 30);
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'read_at', 'id']);
        });

        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_known CHECK (type IN ('new_order', 'customer_cancelled'))");

        // Task 3 left inventory_movements.order_id a plain column because orders did not exist yet.
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreign('order_id')->references('id')->on('orders');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
        });

        Schema::dropIfExists('notifications');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('tenant_order_counters');
    }
};
