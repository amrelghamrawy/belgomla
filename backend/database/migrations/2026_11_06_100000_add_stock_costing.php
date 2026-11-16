<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 costing, slice A (D96, BR-INV09): the weighted average cost per base unit of each SKU, the cost every movement
| moved at, the `receive` movement type, and the cost frozen on each order line at delivery. Costs are per base unit
| with 4 decimals (a carton price ÷ its factor rarely divides to the piaster).
|
| Existing rows (rules/04 §5): nothing is backfilled. No cost was ever recorded, so every average starts unknown (the
| merchant receives goods or sets the cost), past movements and lines delivered before this change keep null, and the
| profit report shows them as not costed instead of guessing.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sku_inventory', function (Blueprint $table): void {
            $table->decimal('avg_cost', 14, 4)->nullable(); // per base unit (BR-U03); null = not known yet
        });
        DB::statement('ALTER TABLE sku_inventory ADD CONSTRAINT sku_inventory_avg_cost_non_negative CHECK (avg_cost IS NULL OR avg_cost >= 0)');

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->decimal('unit_cost', 14, 4)->nullable(); // per base unit the movement moved at (D96 e)
        });
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_unit_cost_non_negative CHECK (unit_cost IS NULL OR unit_cost >= 0)');
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_type_known');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_type_known CHECK (type IN ('opening', 'adjustment', 'sale', 'return', 'receive'))");
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_receive_costed CHECK (type <> 'receive' OR (unit_cost IS NOT NULL AND quantity > 0))");

        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 14, 4)->nullable(); // per base unit, frozen at delivery (D96 f)
        });
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_cost_non_negative CHECK (unit_cost IS NULL OR unit_cost >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_unit_cost_non_negative');
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('unit_cost');
        });

        // A receipt has no V1 type: it becomes the adjustment it would have been, with its note kept as the reason.
        DB::statement("UPDATE inventory_movements SET type = 'adjustment', reason = coalesce(reason, 'استلام بضاعة') WHERE type = 'receive'");
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_receive_costed');
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_type_known');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_type_known CHECK (type IN ('opening', 'adjustment', 'sale', 'return'))");
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_unit_cost_non_negative');
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropColumn('unit_cost');
        });

        DB::statement('ALTER TABLE sku_inventory DROP CONSTRAINT sku_inventory_avg_cost_non_negative');
        Schema::table('sku_inventory', function (Blueprint $table): void {
            $table->dropColumn('avg_cost');
        });
    }
};
