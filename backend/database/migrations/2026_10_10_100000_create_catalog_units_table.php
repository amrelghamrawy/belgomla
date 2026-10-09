<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('status', 20)->default('active'); // active | archived
            $table->timestamps();

            $table->unique(['tenant_id', 'name']); // BR-U01
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
