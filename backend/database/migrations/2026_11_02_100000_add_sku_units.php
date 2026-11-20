<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 13 (BR-U02, BR-U04, D91), step 1 of 2: a SKU is sold in several units (piece, carton of 48, ...). Each unit has
| its own factor, prices, MOQ and tiers; one is the default. Stock stays per SKU in base units (BR-U03).
|
| Additive with a backfill, so the old columns can still be read if this step is rolled back: every SKU gets one default
| unit from its own columns and its tiers move to it; cart lines, order lines and negotiated prices point at that unit.
| The skus price columns become nullable here (the code no longer writes them) and are dropped by the next migration.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained('skus')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units');
            $table->integer('conversion_factor')->default(1); // base units in one of this unit (BR-U03)
            $table->decimal('base_price', 12, 2);
            $table->decimal('base_price_percent', 5, 2)->nullable(); // % off cover_price the base price was derived from (BR-PR10)
            $table->decimal('cover_price', 12, 2)->nullable();
            $table->integer('moq')->default(1);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('status', 10)->default('active');
            $table->timestamps();

            $table->unique(['sku_id', 'unit_id']);
        });

        DB::statement('CREATE UNIQUE INDEX sku_units_one_default ON sku_units (sku_id) WHERE is_default');
        DB::statement('ALTER TABLE sku_units ADD CONSTRAINT sku_units_conversion_factor_positive CHECK (conversion_factor >= 1)');
        DB::statement('ALTER TABLE sku_units ADD CONSTRAINT sku_units_base_price_positive CHECK (base_price > 0)');
        DB::statement('ALTER TABLE sku_units ADD CONSTRAINT sku_units_moq_positive CHECK (moq >= 1)');
        DB::statement("ALTER TABLE sku_units ADD CONSTRAINT sku_units_status_known CHECK (status IN ('active', 'archived'))");
        DB::statement("ALTER TABLE sku_units ADD CONSTRAINT sku_units_default_active CHECK (NOT is_default OR status = 'active')");

        DB::statement(<<<'SQL'
            INSERT INTO sku_units (tenant_id, sku_id, unit_id, conversion_factor, base_price, base_price_percent, cover_price, moq, is_default, sort_order, status, created_at, updated_at)
            SELECT tenant_id, id, selling_unit_id, conversion_factor, base_price, base_price_percent, cover_price, moq, true, 0, 'active', created_at, updated_at
            FROM skus
            SQL);

        Schema::table('sku_price_tiers', function (Blueprint $table): void {
            $table->foreignId('sku_unit_id')->nullable()->constrained('sku_units')->cascadeOnDelete();
        });

        DB::statement('UPDATE sku_price_tiers SET sku_unit_id = u.id FROM sku_units u WHERE u.sku_id = sku_price_tiers.sku_id AND u.is_default');
        DB::statement('ALTER TABLE sku_price_tiers ALTER COLUMN sku_unit_id SET NOT NULL');
        DB::statement('ALTER TABLE sku_price_tiers ALTER COLUMN sku_id DROP NOT NULL');

        Schema::table('sku_price_tiers', function (Blueprint $table): void {
            $table->dropUnique(['sku_id', 'min_quantity']);
            $table->unique(['sku_unit_id', 'min_quantity']); // BR-PR05, per unit
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreignId('sku_unit_id')->nullable()->constrained('sku_units')->cascadeOnDelete();
        });

        DB::statement('UPDATE cart_items SET sku_unit_id = u.id FROM sku_units u WHERE u.sku_id = cart_items.sku_id AND u.is_default');
        DB::statement('ALTER TABLE cart_items ALTER COLUMN sku_unit_id SET NOT NULL');

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique(['cart_id', 'sku_id', 'kind']);
            $table->unique(['cart_id', 'sku_unit_id', 'kind']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('sku_unit_id')->nullable()->constrained('sku_units'); // reference only, like sku_id (BR-PR07)
        });

        DB::statement('UPDATE order_items SET sku_unit_id = u.id FROM sku_units u WHERE u.sku_id = order_items.sku_id AND u.is_default');

        Schema::table('customer_sku_prices', function (Blueprint $table): void {
            $table->foreignId('sku_unit_id')->nullable()->constrained('sku_units')->cascadeOnDelete();
        });

        DB::statement('UPDATE customer_sku_prices SET sku_unit_id = u.id FROM sku_units u WHERE u.sku_id = customer_sku_prices.sku_id AND u.is_default');
        DB::statement('ALTER TABLE customer_sku_prices ALTER COLUMN sku_unit_id SET NOT NULL');

        Schema::table('customer_sku_prices', function (Blueprint $table): void {
            $table->dropUnique(['customer_id', 'sku_id']);
            $table->unique(['customer_id', 'sku_unit_id']); // D91: negotiated per unit
        });

        foreach (['selling_unit_id', 'conversion_factor', 'base_price', 'moq'] as $column) {
            DB::statement("ALTER TABLE skus ALTER COLUMN {$column} DROP NOT NULL");
        }
    }

    public function down(): void
    {
        // Only the default unit maps back onto the single-unit SKU: what other units hold is dropped.
        DB::statement('UPDATE skus SET selling_unit_id = u.unit_id, conversion_factor = u.conversion_factor, base_price = u.base_price, base_price_percent = u.base_price_percent, cover_price = u.cover_price, moq = u.moq FROM sku_units u WHERE u.sku_id = skus.id AND u.is_default');

        foreach (['selling_unit_id', 'conversion_factor', 'base_price', 'moq'] as $column) {
            DB::statement("ALTER TABLE skus ALTER COLUMN {$column} SET NOT NULL");
        }

        DB::statement('DELETE FROM customer_sku_prices p USING sku_units u WHERE u.id = p.sku_unit_id AND NOT u.is_default');

        Schema::table('customer_sku_prices', function (Blueprint $table): void {
            $table->dropUnique(['customer_id', 'sku_unit_id']);
            $table->unique(['customer_id', 'sku_id']);
            $table->dropConstrainedForeignId('sku_unit_id');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sku_unit_id');
        });

        DB::statement('DELETE FROM cart_items c USING sku_units u WHERE u.id = c.sku_unit_id AND NOT u.is_default');

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique(['cart_id', 'sku_unit_id', 'kind']);
            $table->unique(['cart_id', 'sku_id', 'kind']);
            $table->dropConstrainedForeignId('sku_unit_id');
        });

        DB::statement('DELETE FROM sku_price_tiers t USING sku_units u WHERE u.id = t.sku_unit_id AND NOT u.is_default');
        DB::statement('UPDATE sku_price_tiers SET sku_id = u.sku_id FROM sku_units u WHERE u.id = sku_price_tiers.sku_unit_id');
        DB::statement('ALTER TABLE sku_price_tiers ALTER COLUMN sku_id SET NOT NULL');

        Schema::table('sku_price_tiers', function (Blueprint $table): void {
            $table->dropUnique(['sku_unit_id', 'min_quantity']);
            $table->unique(['sku_id', 'min_quantity']);
            $table->dropConstrainedForeignId('sku_unit_id');
        });

        Schema::dropIfExists('sku_units');
    }
};
