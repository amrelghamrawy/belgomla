<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 02 (D24, BR-C05..C08): credit terms (آجل) per customer with tenant defaults, the due date of a delivered
| order, and customer credit — a return's over-collection kept as credit and applied to later orders as a `credit`
| collection.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->decimal('credit_limit', 12, 2)->nullable(); // null = no limit (BR-C05)
            $table->smallInteger('payment_terms_days')->nullable(); // null = due on delivery (BR-C06)
        });

        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_credit_limit_non_negative CHECK (credit_limit IS NULL OR credit_limit >= 0)');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_payment_terms_range CHECK (payment_terms_days IS NULL OR payment_terms_days BETWEEN 0 AND 365)');

        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->smallInteger('default_payment_terms_days')->nullable(); // applied to new customers
            $table->decimal('default_credit_limit', 12, 2)->nullable();
        });

        DB::statement('ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_default_terms_range CHECK (default_payment_terms_days IS NULL OR default_payment_terms_days BETWEEN 0 AND 365)');
        DB::statement('ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_default_limit_non_negative CHECK (default_credit_limit IS NULL OR default_credit_limit >= 0)');

        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('due_at')->nullable(); // delivered_at + the customer's terms at delivery (BR-C06)
        });

        DB::statement('CREATE INDEX orders_tenant_due_index ON orders (tenant_id, due_at) WHERE status = \'delivered\'');

        Schema::create('customer_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('source_return_id')->nullable()->constrained('order_returns');
            $table->decimal('amount', 12, 2);
            $table->decimal('remaining', 12, 2);
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('tenant_users'); // null = system
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('tenant_users');
            $table->timestamps();

            $table->index(['customer_id', 'id']);
        });

        DB::statement('ALTER TABLE customer_credits ADD CONSTRAINT customer_credits_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE customer_credits ADD CONSTRAINT customer_credits_remaining_within CHECK (remaining >= 0 AND remaining <= amount)');
        DB::statement('ALTER TABLE customer_credits ADD CONSTRAINT customer_credits_void_complete CHECK ((voided_at IS NULL) = (void_reason IS NULL))');

        Schema::table('order_returns', function (Blueprint $table): void {
            $table->string('refund_method', 10)->nullable(); // how refund_amount went back: cash or credit (BR-C07)
        });

        DB::statement("ALTER TABLE order_returns ADD CONSTRAINT order_returns_refund_method_known CHECK (refund_method IS NULL OR refund_method IN ('cash', 'credit'))");
        DB::statement('UPDATE order_returns SET refund_method = \'cash\' WHERE refund_amount > 0');

        Schema::table('collections', function (Blueprint $table): void {
            $table->foreignId('customer_credit_id')->nullable()->constrained('customer_credits');
        });

        DB::statement('ALTER TABLE collections DROP CONSTRAINT collections_method_known');
        DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_method_known CHECK (method IN ('cash', 'credit'))");
        DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_credit_has_source CHECK ((method = 'credit') = (customer_credit_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE collections DROP CONSTRAINT collections_credit_has_source');
        DB::statement('ALTER TABLE collections DROP CONSTRAINT collections_method_known');
        DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_method_known CHECK (method IN ('cash'))");

        Schema::table('collections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_credit_id');
        });

        Schema::table('order_returns', function (Blueprint $table): void {
            $table->dropColumn('refund_method');
        });

        Schema::dropIfExists('customer_credits');

        DB::statement('DROP INDEX orders_tenant_due_index');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('due_at');
        });

        DB::statement('ALTER TABLE tenant_settings DROP CONSTRAINT tenant_settings_default_limit_non_negative');
        DB::statement('ALTER TABLE tenant_settings DROP CONSTRAINT tenant_settings_default_terms_range');

        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn(['default_payment_terms_days', 'default_credit_limit']);
        });

        DB::statement('ALTER TABLE customers DROP CONSTRAINT customers_payment_terms_range');
        DB::statement('ALTER TABLE customers DROP CONSTRAINT customers_credit_limit_non_negative');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['credit_limit', 'payment_terms_days']);
        });
    }
};
