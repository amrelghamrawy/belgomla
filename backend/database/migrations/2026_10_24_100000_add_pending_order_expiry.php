<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 04 (BR-INV07, D81): a store may let pending orders cancel themselves after N hours without confirmation.
| The system cancels them (cancelled_by_type = system) and tells the merchant (order_expired).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->smallInteger('pending_expiry_hours')->nullable(); // null = off
        });

        DB::statement('ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_pending_expiry_hours_range CHECK (pending_expiry_hours IS NULL OR pending_expiry_hours BETWEEN 1 AND 720)');

        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_cancelled_by_known');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_cancelled_by_known CHECK (cancelled_by_type IS NULL OR cancelled_by_type IN ('customer', 'tenant_user', 'system'))");

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_known');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_known CHECK (type IN ('new_order', 'customer_cancelled', 'whatsapp_dropped', 'order_expired'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_known');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_known CHECK (type IN ('new_order', 'customer_cancelled', 'whatsapp_dropped'))");

        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_cancelled_by_known');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_cancelled_by_known CHECK (cancelled_by_type IS NULL OR cancelled_by_type IN ('customer', 'tenant_user'))");

        DB::statement('ALTER TABLE tenant_settings DROP CONSTRAINT tenant_settings_pending_expiry_hours_range');

        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn('pending_expiry_hours');
        });
    }
};
