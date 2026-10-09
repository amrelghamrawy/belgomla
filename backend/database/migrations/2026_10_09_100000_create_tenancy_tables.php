<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('subdomain', 63)->unique();
            $table->string('name', 150);
            $table->string('status', 20)->default('active'); // active | suspended
            $table->string('vertical', 30)->default('none');
            $table->timestamps();
        });

        Schema::create('tenant_settings', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->char('primary_color', 7)->default('#0F766E');
            $table->char('secondary_color', 7)->default('#F59E0B');
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->text('address')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('working_hours')->nullable();
            $table->char('currency', 3)->default('EGP');
            $table->boolean('mov_enabled')->default(false);
            $table->decimal('mov_amount', 12, 2)->nullable();
            $table->boolean('require_login_for_prices')->default(false);
            $table->boolean('require_customer_approval')->default(false);
            $table->unsignedSmallInteger('stale_pending_hours')->default(24);
            $table->timestamps();
        });

        Schema::create('tenant_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('email', 190);
            $table->string('password');
            $table->string('role', 20)->default('owner');
            $table->string('status', 20)->default('active'); // active | disabled
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('tenants');
    }
};
