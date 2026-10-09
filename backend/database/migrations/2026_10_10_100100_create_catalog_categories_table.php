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
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories');
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->string('path', 500)->default('/'); // materialized "/1/7/23/", own id included (Tech §9b)
            $table->integer('sort_order')->default(0);
            $table->string('image_path')->nullable();
            $table->string('status', 20)->default('active'); // active | archived (BR-P06)
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_parent_not_self CHECK (parent_id IS NULL OR parent_id <> id)');
        DB::statement('CREATE INDEX categories_tenant_path_index ON categories (tenant_id, path varchar_pattern_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
