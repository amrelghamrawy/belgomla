<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 11 (BR-PR12..PR14, D89): customer groups (wholesale levels with one % off the base price) and negotiated prices
| per customer and SKU. A customer belongs to at most one group; an archived group stops applying but its customers keep
| the id. Order lines snapshot where their price came from (`price_source`: base, tier, group or customer).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('name', 100);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->string('status', 10)->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        DB::statement('ALTER TABLE customer_groups ADD CONSTRAINT customer_groups_discount_range CHECK (discount_percent >= 0 AND discount_percent <= 90)');
        DB::statement("ALTER TABLE customer_groups ADD CONSTRAINT customer_groups_status_known CHECK (status IN ('active', 'archived'))");

        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups');
            $table->index(['tenant_id', 'customer_group_id']);
        });

        Schema::create('customer_sku_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('sku_id')->constrained('skus');
            $table->decimal('unit_price', 12, 2);
            $table->string('note', 300)->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'sku_id']);
            $table->index(['tenant_id', 'sku_id']);
        });

        DB::statement('ALTER TABLE customer_sku_prices ADD CONSTRAINT customer_sku_prices_unit_price_positive CHECK (unit_price > 0)');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('price_source', 10)->default('base');
        });

        DB::statement("UPDATE order_items SET price_source = 'tier' WHERE tier_min_quantity IS NOT NULL");
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_price_source_known CHECK (price_source IN ('base', 'tier', 'group', 'customer'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_price_source_known');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('price_source');
        });

        Schema::dropIfExists('customer_sku_prices');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'customer_group_id']);
            $table->dropConstrainedForeignId('customer_group_id');
        });

        Schema::dropIfExists('customer_groups');
    }
};
