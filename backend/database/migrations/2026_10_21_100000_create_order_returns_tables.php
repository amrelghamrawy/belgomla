<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 01 (D20, BR-R01..R06): returns after delivery. The `return` movement type and the `order_returns`
| name were reserved in V1, so nothing existing is migrated; orders gain `returned_total` and collections a
| `kind` (a refund lowers the collected figure) plus the return a refund belongs to.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders');
            $table->integer('number'); // per order: 1, 2, … (BR-R05)
            $table->decimal('total', 12, 2);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->string('reason', 300);
            $table->timestamp('returned_at');
            $table->foreignId('recorded_by')->nullable()->constrained('tenant_users'); // null = system
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('tenant_users');
            $table->timestamps();

            $table->unique(['order_id', 'number']);
        });

        DB::statement('CREATE INDEX order_returns_tenant_returned_index ON order_returns (tenant_id, returned_at DESC)');
        DB::statement('ALTER TABLE order_returns ADD CONSTRAINT order_returns_total_positive CHECK (total > 0)');
        DB::statement('ALTER TABLE order_returns ADD CONSTRAINT order_returns_refund_non_negative CHECK (refund_amount >= 0)');
        DB::statement('ALTER TABLE order_returns ADD CONSTRAINT order_returns_number_positive CHECK (number >= 1)');
        DB::statement('ALTER TABLE order_returns ADD CONSTRAINT order_returns_void_complete CHECK ((voided_at IS NULL) = (void_reason IS NULL))');

        // quantity_base = quantity × the order item's conversion_factor, kept by RecordReturn (the factor lives on the item).
        Schema::create('order_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_return_id')->constrained('order_returns')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items');
            $table->integer('quantity');
            $table->integer('quantity_base');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index(['order_return_id', 'id']);
            $table->index('order_item_id');
        });

        DB::statement('ALTER TABLE order_return_items ADD CONSTRAINT order_return_items_quantity_positive CHECK (quantity >= 1)');

        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('returned_total', 12, 2)->default(0); // Σ live returns (BR-O08: net = order_total − returned_total)
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_returned_non_negative CHECK (returned_total >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_returned_within_total CHECK (returned_total <= order_total)');

        Schema::table('collections', function (Blueprint $table): void {
            $table->string('kind', 10)->default('payment');
            $table->foreignId('order_return_id')->nullable()->constrained('order_returns'); // the return a refund pays back
        });

        DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_kind_known CHECK (kind IN ('payment', 'refund'))");
        DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_refund_of_return CHECK ((kind = 'refund') = (order_return_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE collections DROP CONSTRAINT collections_refund_of_return');
        DB::statement('ALTER TABLE collections DROP CONSTRAINT collections_kind_known');

        Schema::table('collections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_return_id');
            $table->dropColumn('kind');
        });

        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_returned_within_total');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_returned_non_negative');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('returned_total');
        });

        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
    }
};
