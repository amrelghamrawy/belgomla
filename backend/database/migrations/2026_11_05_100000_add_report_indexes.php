<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| V2 Task 16 (D94): indexes for the advanced reports. Sales, product, customer and collection figures read delivered
| orders by `delivered_at`; inventory analytics sums stock movements by type over a date range. `order_items
| (tenant_id, sku_id)`, `order_returns (tenant_id, returned_at)` and `collections (tenant_id, collected_at)` exist
| already. Nothing to backfill.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS orders_tenant_status_delivered_index ON orders (tenant_id, status, delivered_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS inventory_movements_tenant_type_created_index ON inventory_movements (tenant_id, type, created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_tenant_status_delivered_index');
        DB::statement('DROP INDEX IF EXISTS inventory_movements_tenant_type_created_index');
    }
};
