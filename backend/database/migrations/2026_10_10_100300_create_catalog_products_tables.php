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
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories');
            $table->string('name', 200);
            $table->string('slug', 220);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active'); // active | coming_soon | inactive (BR-P04)
            $table->jsonb('custom_fields')->default('{}');
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status', 'category_id']);
        });

        DB::statement('CREATE INDEX products_custom_fields_gin ON products USING GIN (custom_fields)');
        DB::statement('CREATE INDEX products_name_trgm ON products USING GIN (name gin_trgm_ops)');

        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('path');
            $table->boolean('is_main')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX product_images_one_main ON product_images (product_id) WHERE is_main');

        Schema::create('product_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('related_product_id')->index()->constrained('products')->cascadeOnDelete();
            $table->string('type', 20); // revision_of | companion (BR-P10)
            $table->timestamps();

            $table->unique(['product_id', 'related_product_id', 'type']);
        });

        DB::statement('ALTER TABLE product_relations ADD CONSTRAINT product_relations_not_self CHECK (related_product_id <> product_id)');

        Schema::create('skus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->index()->constrained('products');
            $table->string('code', 60);
            $table->string('name', 200)->nullable();
            $table->foreignId('selling_unit_id')->constrained('units');
            $table->foreignId('base_unit_id')->constrained('units');
            $table->integer('conversion_factor')->default(1);
            $table->decimal('cover_price', 12, 2)->nullable();
            $table->decimal('base_price', 12, 2);
            $table->decimal('base_price_percent', 5, 2)->nullable();
            $table->integer('moq')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']); // BR-P03
        });

        DB::statement('ALTER TABLE skus ADD CONSTRAINT skus_conversion_factor_positive CHECK (conversion_factor >= 1)');
        DB::statement('ALTER TABLE skus ADD CONSTRAINT skus_base_price_positive CHECK (base_price > 0)');
        DB::statement('ALTER TABLE skus ADD CONSTRAINT skus_moq_positive CHECK (moq >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('skus');
        Schema::dropIfExists('product_relations');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
    }
};
