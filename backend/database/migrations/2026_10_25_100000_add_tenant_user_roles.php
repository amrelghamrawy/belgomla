<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 05 (BR-T06, D82): five fixed roles for dashboard users. A notification says which permission may see it,
| so a warehouse worker's bell shows only what their role covers.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tenant_users ADD CONSTRAINT tenant_users_role_known CHECK (role IN ('owner', 'manager', 'sales', 'warehouse', 'accountant'))");

        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('permission', 40)->default('orders.manage'); // the permission a user needs to see it
        });

        DB::statement("UPDATE notifications SET permission = 'settings.manage' WHERE type = 'whatsapp_dropped'");
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('permission');
        });

        DB::statement('ALTER TABLE tenant_users DROP CONSTRAINT tenant_users_role_known');
    }
};
