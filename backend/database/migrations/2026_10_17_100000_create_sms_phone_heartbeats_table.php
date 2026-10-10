<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform table: the SMS phone belongs to the platform, not a tenant (D49, tasks/11 monitoring).
        Schema::create('sms_phone_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('phone_id')->unique(); // Hypersender phone uuid
            $table->timestamp('last_seen_at'); // when we received the heartbeat, our clock
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_phone_heartbeats');
    }
};
