<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 12 (BR-PR15, D90): time-boxed promotions, one % off the base price of the SKUs a product filter or an explicit
| list selected (materialised in promotion_skus when the promotion is saved, so pricing is a lookup). Order lines record
| the promotion that set their list price (`price_source = promotion`, `promotion_id`).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('name', 100);
            $table->decimal('discount_percent', 5, 2);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->jsonb('filter')->nullable();
            $table->string('status', 10)->default('scheduled');
            $table->foreignId('created_by')->nullable()->constrained('tenant_users');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'ends_at']);
        });

        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_discount_range CHECK (discount_percent > 0 AND discount_percent < 90)');
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_window CHECK (ends_at > starts_at)');
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_status_known CHECK (status IN ('draft', 'scheduled', 'active', 'ended', 'cancelled'))");

        Schema::create('promotion_skus', function (Blueprint $table): void {
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained('skus');

            $table->primary(['promotion_id', 'sku_id']);
            $table->index('sku_id');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('promotion_id')->nullable()->constrained('promotions');
        });

        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_price_source_known');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_price_source_known CHECK (price_source IN ('base', 'tier', 'group', 'customer', 'promotion'))");
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_promotion_with_source CHECK ((price_source = 'promotion') = (promotion_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_promotion_with_source');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_price_source_known');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_price_source_known CHECK (price_source IN ('base', 'tier', 'group', 'customer'))");

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('promotion_id');
        });

        Schema::dropIfExists('promotion_skus');
        Schema::dropIfExists('promotions');
    }
};
