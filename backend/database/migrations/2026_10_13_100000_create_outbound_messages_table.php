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
        Schema::create('outbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('uuid')->unique(); // sent to the provider as our request reference
            $table->string('to_phone', 20); // E.164
            $table->string('purpose', 20);
            $table->string('channel', 20);
            $table->string('provider', 30);
            $table->string('provider_request_id')->nullable()->index(); // Hypersender queued_request_uuid
            $table->string('provider_message_id')->nullable()->index();
            $table->string('status', 20);
            $table->string('error_code', 50)->nullable();
            $table->text('body')->nullable(); // encrypted; cleared once the message is final (Tech §13)
            $table->foreignId('fallback_of_id')->nullable()->unique()->constrained('outbound_messages')->nullOnDelete(); // BR-CU07: at most one SMS per failed WhatsApp row
            $table->decimal('cost', 8, 4)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'to_phone', 'created_at']);
        });

        DB::statement("ALTER TABLE outbound_messages ADD CONSTRAINT outbound_messages_purpose_known CHECK (purpose IN ('otp', 'invitation', 'system_alert'))");
        DB::statement("ALTER TABLE outbound_messages ADD CONSTRAINT outbound_messages_channel_known CHECK (channel IN ('whatsapp', 'sms'))");
        DB::statement("ALTER TABLE outbound_messages ADD CONSTRAINT outbound_messages_status_known CHECK (status IN ('queued', 'sent', 'delivered', 'failed', 'fell_back'))");
        DB::statement("ALTER TABLE outbound_messages ADD CONSTRAINT outbound_messages_fallback_is_sms CHECK (fallback_of_id IS NULL OR channel = 'sms')");
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
    }
};
