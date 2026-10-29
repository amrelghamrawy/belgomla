<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 15 (BR-P15, D93): bundles. A product is `simple` (every product so far) or a `bundle`: one SKU sold in one unit
| (factor 1) made of component SKU units with fixed quantities (`bundle_items`), priced at a fixed price or the
| components' sum less a % (`bundle_pricing`, Pricing's table). A bundle has no stock of its own (no sku_inventory row
| is used); its availability is derived from the components (BR-INV02).
|
| An order keeps a bundle as one line (`is_bundle`) with the price and total, and one child line per component
| (`parent_item_id`) carrying the quantities, the reservation and the allocated price; only top-level lines count in
| the items total (the actions keep that).
|
| Every existing product is simple and no order has bundle lines, so nothing needs a backfill.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('type', 10)->default('simple');
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_known CHECK (type IN ('simple', 'bundle'))");

        Schema::create('bundle_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('bundle_sku_id')->constrained('skus')->cascadeOnDelete();
            $table->foreignId('component_sku_unit_id')->index()->constrained('sku_units'); // no action: a unit in a bundle is archived, never deleted
            $table->integer('quantity');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['bundle_sku_id', 'component_sku_unit_id']);
        });

        DB::statement('ALTER TABLE bundle_items ADD CONSTRAINT bundle_items_quantity_positive CHECK (quantity >= 1)');

        Schema::create('bundle_pricing', function (Blueprint $table): void {
            $table->foreignId('bundle_sku_id')->primary()->constrained('skus')->cascadeOnDelete();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('mode', 16);
            $table->decimal('value', 12, 2);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE bundle_pricing ADD CONSTRAINT bundle_pricing_mode_known CHECK (mode IN ('fixed', 'percent_off_sum'))");
        DB::statement("ALTER TABLE bundle_pricing ADD CONSTRAINT bundle_pricing_value_valid CHECK ((mode = 'fixed' AND value > 0) OR (mode = 'percent_off_sum' AND value >= 0 AND value < 100))");

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('parent_item_id')->nullable()->index()->constrained('order_items');
            $table->boolean('is_bundle')->default(false);
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_bundle_top_level CHECK (NOT is_bundle OR parent_item_id IS NULL)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_bundle_holds_nothing CHECK (NOT is_bundle OR NOT reserved)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_bundle_holds_nothing');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_bundle_top_level');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_item_id');
            $table->dropColumn('is_bundle');
        });

        Schema::dropIfExists('bundle_pricing');
        Schema::dropIfExists('bundle_items');

        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_type_known');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
