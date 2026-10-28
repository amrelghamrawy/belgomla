<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 13 (D91), step 2 of 2: once the code reads prices, MOQ and tiers from `sku_units`, the SKU's own copies go:
| `skus.selling_unit_id, conversion_factor, cover_price, base_price, base_price_percent, moq` and `sku_price_tiers.sku_id`.
| Rolling back puts them back filled from each SKU's default unit (step 1 can then be rolled back too).
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE skus DROP CONSTRAINT skus_conversion_factor_positive');
        DB::statement('ALTER TABLE skus DROP CONSTRAINT skus_base_price_positive');
        DB::statement('ALTER TABLE skus DROP CONSTRAINT skus_moq_positive');

        Schema::table('skus', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('selling_unit_id');
            $table->dropColumn(['conversion_factor', 'cover_price', 'base_price', 'base_price_percent', 'moq']);
        });

        Schema::table('sku_price_tiers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sku_id');
        });
    }

    public function down(): void
    {
        Schema::table('sku_price_tiers', function (Blueprint $table): void {
            $table->foreignId('sku_id')->nullable()->constrained('skus')->cascadeOnDelete();
        });

        DB::statement('UPDATE sku_price_tiers SET sku_id = u.sku_id FROM sku_units u WHERE u.id = sku_price_tiers.sku_unit_id');

        Schema::table('skus', function (Blueprint $table): void {
            $table->foreignId('selling_unit_id')->nullable()->constrained('units');
            $table->integer('conversion_factor')->nullable()->default(1);
            $table->decimal('cover_price', 12, 2)->nullable();
            $table->decimal('base_price', 12, 2)->nullable();
            $table->decimal('base_price_percent', 5, 2)->nullable();
            $table->integer('moq')->nullable()->default(1);
        });

        DB::statement('UPDATE skus SET selling_unit_id = u.unit_id, conversion_factor = u.conversion_factor, base_price = u.base_price, base_price_percent = u.base_price_percent, cover_price = u.cover_price, moq = u.moq FROM sku_units u WHERE u.sku_id = skus.id AND u.is_default');

        DB::statement('ALTER TABLE skus ADD CONSTRAINT skus_conversion_factor_positive CHECK (conversion_factor >= 1)');
        DB::statement('ALTER TABLE skus ADD CONSTRAINT skus_base_price_positive CHECK (base_price > 0)');
        DB::statement('ALTER TABLE skus ADD CONSTRAINT skus_moq_positive CHECK (moq >= 1)');
    }
};
