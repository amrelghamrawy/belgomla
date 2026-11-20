<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 14 (BR-P02, BR-P14, D92): a product has 1..N SKUs that differ by option values (colour, size). At most two
| options per product with up to twenty values each (checked in the request); each SKU names one value of every option
| (`sku_option_values`, one row per SKU and option); the combination is unique per product (checked in the action).
|
| `skus.status` hides one variant (active | inactive) and `skus.sort_order` keeps the merchant's order. Every existing SKU
| is active, ordered by id within its product; existing products have no options, so nothing else needs a backfill.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name', 50);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'name']);
        });

        Schema::create('product_option_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_option_id')->constrained('product_options')->cascadeOnDelete();
            $table->string('value', 50);
            $table->integer('sort_order')->default(0);
            $table->foreignId('image_id')->nullable()->constrained('product_images')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_option_id', 'value']);
        });

        Schema::create('sku_option_values', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained('skus')->cascadeOnDelete();
            $table->foreignId('product_option_id')->constrained('product_options')->cascadeOnDelete();
            $table->foreignId('product_option_value_id')->constrained('product_option_values')->cascadeOnDelete();

            $table->primary(['sku_id', 'product_option_id']);
            $table->index('product_option_value_id');
        });

        Schema::table('skus', function (Blueprint $table): void {
            $table->string('status', 10)->default('active');
            $table->integer('sort_order')->default(0);
        });

        DB::statement("ALTER TABLE skus ADD CONSTRAINT skus_status_known CHECK (status IN ('active', 'inactive'))");
        DB::statement(<<<'SQL'
            UPDATE skus SET sort_order = ranked.position
            FROM (SELECT id, row_number() OVER (PARTITION BY product_id ORDER BY id) - 1 AS position FROM skus) AS ranked
            WHERE ranked.id = skus.id
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_option_values');
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_options');

        DB::statement('ALTER TABLE skus DROP CONSTRAINT IF EXISTS skus_status_known');

        Schema::table('skus', function (Blueprint $table): void {
            $table->dropColumn(['status', 'sort_order']);
        });
    }
};
