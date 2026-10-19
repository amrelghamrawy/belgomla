<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 03 (BR-N01, BR-N03, D79, D80): customers hear about their order's status over WhatsApp in the store's own
| words, unless they opt out; the order page lists the messages it sent; merchants are told in the dashboard when
| their store number drops (whatsapp_dropped, left over from tasks/12).
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE outbound_messages DROP CONSTRAINT outbound_messages_purpose_known');
        DB::statement("ALTER TABLE outbound_messages ADD CONSTRAINT outbound_messages_purpose_known CHECK (purpose IN ('otp', 'invitation', 'system_alert', 'order_status'))");

        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->constrained('orders'); // the order an order_status message is about
            $table->index(['order_id', 'id']);
        });

        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->jsonb('order_notify_statuses')->default(json_encode(['confirmed', 'out_for_delivery', 'delivered', 'cancelled']));
            $table->jsonb('order_status_messages')->nullable(); // status => template; a missing or null entry = the default
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('order_notifications_opt_out')->default(false);
        });

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_known');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_known CHECK (type IN ('new_order', 'customer_cancelled', 'whatsapp_dropped'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_known');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_known CHECK (type IN ('new_order', 'customer_cancelled'))");

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('order_notifications_opt_out');
        });

        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn(['order_notify_statuses', 'order_status_messages']);
        });

        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropIndex(['order_id', 'id']);
            $table->dropConstrainedForeignId('order_id');
        });

        DB::statement('ALTER TABLE outbound_messages DROP CONSTRAINT outbound_messages_purpose_known');
        DB::statement("ALTER TABLE outbound_messages ADD CONSTRAINT outbound_messages_purpose_known CHECK (purpose IN ('otp', 'invitation', 'system_alert'))");
    }
};
