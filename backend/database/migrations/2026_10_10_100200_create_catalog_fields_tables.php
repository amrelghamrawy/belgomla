<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_field_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 50);
            $table->string('label', 100);
            $table->string('type', 20); // text | number | select | boolean (BR-P08)
            $table->boolean('is_required')->default(false);
            $table->boolean('show_on_storefront')->default(true);
            $table->boolean('is_filterable')->default(false);
            $table->integer('filter_order')->default(0);
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('product_field_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('definition_id')->constrained('product_field_definitions')->cascadeOnDelete();
            $table->string('label', 100);
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['definition_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_field_options');
        Schema::dropIfExists('product_field_definitions');
    }
};
