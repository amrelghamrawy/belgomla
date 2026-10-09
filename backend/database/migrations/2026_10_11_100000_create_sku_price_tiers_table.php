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
        Schema::create('sku_price_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained('skus')->cascadeOnDelete();
            $table->integer('min_quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_percent', 5, 2)->nullable(); // % off cover price used to derive unit_price, display only (BR-PR10)
            $table->timestamps();

            $table->unique(['sku_id', 'min_quantity']); // BR-PR05
        });

        DB::statement('ALTER TABLE sku_price_tiers ADD CONSTRAINT sku_price_tiers_min_quantity_positive CHECK (min_quantity >= 1)');
        DB::statement('ALTER TABLE sku_price_tiers ADD CONSTRAINT sku_price_tiers_unit_price_positive CHECK (unit_price > 0)');
        DB::statement('ALTER TABLE sku_price_tiers ADD CONSTRAINT sku_price_tiers_discount_percent_range CHECK (discount_percent > 0 AND discount_percent < 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_price_tiers');
    }
};
