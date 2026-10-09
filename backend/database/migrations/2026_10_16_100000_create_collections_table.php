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
        // BR-C02: one cash collection of an order; voided, never deleted. Σ non-voided ≤ order_total is kept by
        // RecordCollection under the order's row lock, and orders.collected_amount follows every insert/void.
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders');
            $table->decimal('amount', 12, 2);
            $table->string('method', 20)->default('cash');
            $table->timestamp('collected_at');
            $table->foreignId('recorded_by')->nullable()->constrained('tenant_users'); // null = system
            $table->string('note', 300)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('tenant_users');
            $table->timestamps();
        });

        DB::statement('CREATE INDEX collections_order_active_index ON collections (order_id) WHERE voided_at IS NULL');
        DB::statement('CREATE INDEX collections_tenant_collected_index ON collections (tenant_id, collected_at DESC)');
        DB::statement('ALTER TABLE collections ADD CONSTRAINT collections_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_method_known CHECK (method IN ('cash'))");
        DB::statement('ALTER TABLE collections ADD CONSTRAINT collections_void_complete CHECK ((voided_at IS NULL) = (void_reason IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};
