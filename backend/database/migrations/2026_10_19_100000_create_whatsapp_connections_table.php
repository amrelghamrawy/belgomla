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
        Schema::create('whatsapp_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete(); // D70: one store number per tenant
            $table->uuid('session_key')->unique(); // the only id wa-gateway sees; replaced on every new pairing
            $table->string('status', 20);
            $table->string('phone', 20)->nullable(); // E.164, known once the session opens
            $table->string('disconnect_reason', 50)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE whatsapp_connections ADD CONSTRAINT whatsapp_connections_status_known CHECK (status IN ('pairing', 'connected', 'disconnected', 'logged_out', 'error'))");
        // tasks/12 lesson 2: one number is live on one store only.
        DB::statement("CREATE UNIQUE INDEX whatsapp_connections_connected_phone ON whatsapp_connections (phone) WHERE status = 'connected'");
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connections');
    }
};
