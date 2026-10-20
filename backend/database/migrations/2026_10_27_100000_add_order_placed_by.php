<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 07 (BR-O11, D84): who placed an order, the customer at checkout or a dashboard user on the customer's behalf.
| Every existing order was placed by its customer.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('placed_by_type', 12)->default('customer');
            $table->unsignedBigInteger('placed_by_id')->nullable(); // customers.id or tenant_users.id, by placed_by_type
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_placed_by_type_known CHECK (placed_by_type IN ('customer', 'tenant_user'))");
        DB::statement('UPDATE orders SET placed_by_id = customer_id');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_placed_by_type_known');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['placed_by_type', 'placed_by_id']);
        });
    }
};
