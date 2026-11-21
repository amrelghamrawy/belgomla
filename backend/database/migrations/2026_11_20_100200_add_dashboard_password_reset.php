<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D112, BR-T10: a dashboard user who forgot their password gets a code on WhatsApp, at the mobile number stored on
 * their user (E.164, optional). Codes are kept like customer codes (BR-CU07): only a keyed hash, 10 minutes,
 * 5 attempts, single use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable(); // E.164; where the reset code goes (D112)
        });

        Schema::create('tenant_user_otps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tenant_user_id')->constrained('tenant_users')->cascadeOnDelete();
            $table->string('code_hash'); // never the plain code (BR-CU07)
            $table->timestamp('expires_at');
            $table->smallInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user_otps');

        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};
