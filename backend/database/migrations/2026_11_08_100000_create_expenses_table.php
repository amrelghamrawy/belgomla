<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 19 slice A (D100): what the shop spends to run, one row per expense, on a business day. Voided with a reason,
| never edited or deleted. New table, nothing to backfill.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('spent_on'); // a business day (D60)
            $table->decimal('amount', 12, 2);
            $table->string('category', 20);
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('tenant_users');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('tenant_users');
            $table->timestamps();
            $table->index(['tenant_id', 'spent_on']);
        });
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount > 0 AND amount <= 10000000)');
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_category_known CHECK (category IN ('rent', 'salaries', 'transport', 'utilities', 'maintenance', 'packaging', 'marketing', 'fees', 'other'))");
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_other_has_note CHECK (category <> 'other' OR note IS NOT NULL)");
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_void_complete CHECK ((voided_at IS NULL) = (void_reason IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
