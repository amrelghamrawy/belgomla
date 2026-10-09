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
        Schema::create('sku_inventory', function (Blueprint $table): void {
            $table->foreignId('sku_id')->primary()->constrained('skus')->cascadeOnDelete();
            $table->foreignId('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->integer('on_hand')->default(0); // base units (BR-U03)
            $table->integer('reserved')->default(0); // base units held by open orders (BR-INV03)
            $table->timestamps();
        });

        DB::statement('ALTER TABLE sku_inventory ADD CONSTRAINT sku_inventory_on_hand_non_negative CHECK (on_hand >= 0)');
        DB::statement('ALTER TABLE sku_inventory ADD CONSTRAINT sku_inventory_reserved_non_negative CHECK (reserved >= 0)');
        DB::statement('ALTER TABLE sku_inventory ADD CONSTRAINT sku_inventory_reserved_within_on_hand CHECK (reserved <= on_hand)'); // BR-INV08

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained('skus')->cascadeOnDelete();
            $table->string('type', 20);
            $table->integer('quantity'); // signed base units, a sale is negative
            $table->integer('on_hand_after');
            $table->unsignedBigInteger('order_id')->nullable(); // FK added with the orders table (Task 6)
            $table->string('reason', 200)->nullable();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'sku_id', 'id']);
        });

        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_type_known CHECK (type IN ('opening', 'adjustment', 'sale', 'return'))");
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_adjustment_reason CHECK (type <> 'adjustment' OR reason IS NOT NULL)");
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_on_hand_after_non_negative CHECK (on_hand_after >= 0)');

        // Every SKU created before Inventory starts with an empty stock row.
        DB::statement('INSERT INTO sku_inventory (sku_id, tenant_id, on_hand, reserved, created_at, updated_at) SELECT id, tenant_id, 0, 0, now(), now() FROM skus');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('sku_inventory');
    }
};
