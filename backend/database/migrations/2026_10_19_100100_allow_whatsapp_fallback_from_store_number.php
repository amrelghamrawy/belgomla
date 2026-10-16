<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| D70: a message from the store's number that fails is re-sent from the platform number, so a fallback copy
| may now be a WhatsApp row too, as long as it does not go through the store number again.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE outbound_messages DROP CONSTRAINT outbound_messages_fallback_is_sms');
        DB::statement("ALTER TABLE outbound_messages ADD CONSTRAINT outbound_messages_fallback_known CHECK (fallback_of_id IS NULL OR channel = 'sms' OR provider <> 'wa_gateway')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE outbound_messages DROP CONSTRAINT outbound_messages_fallback_known');
        DB::statement("ALTER TABLE outbound_messages ADD CONSTRAINT outbound_messages_fallback_is_sms CHECK (fallback_of_id IS NULL OR channel = 'sms')");
    }
};
