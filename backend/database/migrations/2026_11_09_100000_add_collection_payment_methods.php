<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V2 Task 20 slice C (D103, BR-C10): a payment or a refund records how the money moved (cash, InstaPay, a wallet or
 * a bank transfer) with an optional reference; `credit` stays the system's method for customer credit (BR-C07) and
 * carries no reference. Old rows stay `cash` (D103 d).
 */
return new class extends Migration
{
    private const array METHODS = ['cash', 'instapay', 'wallet', 'bank_transfer'];

    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table): void {
            $table->string('reference', 60)->nullable();
        });

        DB::statement('ALTER TABLE collections DROP CONSTRAINT collections_method_known');
        DB::statement('ALTER TABLE collections ADD CONSTRAINT collections_method_known CHECK (method IN ('.$this->list([...self::METHODS, 'credit']).'))');
        DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_credit_has_no_reference CHECK (method <> 'credit' OR reference IS NULL)");

        Schema::table('order_returns', function (Blueprint $table): void {
            $table->string('refund_method', 20)->nullable()->change();
        });

        DB::statement('ALTER TABLE order_returns DROP CONSTRAINT order_returns_refund_method_known');
        DB::statement('ALTER TABLE order_returns ADD CONSTRAINT order_returns_refund_method_known CHECK (refund_method IS NULL OR refund_method IN ('.$this->list([...self::METHODS, 'credit']).'))');
    }

    public function down(): void
    {
        $new = $this->list(array_slice(self::METHODS, 1));
        $used = DB::table('collections')->whereRaw("method IN ({$new})")->exists()
            || DB::table('order_returns')->whereRaw("refund_method IN ({$new})")->exists();

        if ($used) {
            throw new RuntimeException('Cannot roll back payment methods: collections or returns already use instapay, wallet or bank_transfer.');
        }

        DB::statement('ALTER TABLE order_returns DROP CONSTRAINT order_returns_refund_method_known');
        DB::statement("ALTER TABLE order_returns ADD CONSTRAINT order_returns_refund_method_known CHECK (refund_method IS NULL OR refund_method IN ('cash', 'credit'))");

        Schema::table('order_returns', function (Blueprint $table): void {
            $table->string('refund_method', 10)->nullable()->change();
        });

        DB::statement('ALTER TABLE collections DROP CONSTRAINT collections_credit_has_no_reference');
        DB::statement('ALTER TABLE collections DROP CONSTRAINT collections_method_known');
        DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_method_known CHECK (method IN ('cash', 'credit'))");

        Schema::table('collections', function (Blueprint $table): void {
            $table->dropColumn('reference');
        });
    }

    /**
     * @param  list<string>  $codes
     */
    private function list(array $codes): string
    {
        return implode(', ', array_map(static fn (string $code): string => "'{$code}'", $codes));
    }
};
