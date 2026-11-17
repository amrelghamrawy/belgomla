<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 costing, slice B (D97): an adjustment names its reason from a fixed list (`reason_code`); the free text `reason`
| stays, now the merchant's note or the reason's label. Existing rows (rules/04 §5): only the catalog import's
| adjustments have a reason we can name for certain (its fixed text «استيراد», D95 e), so they become `import`; every
| other old adjustment keeps its free text and no code, and is shown as it was written.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->string('reason_code', 20)->nullable();
        });
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_reason_code_known CHECK (reason_code IS NULL OR reason_code IN ('stocktake', 'damaged', 'expired', 'recording_error', 'found', 'other', 'import'))");
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_reason_code_on_adjustments CHECK (reason_code IS NULL OR type = 'adjustment')");
        DB::statement("UPDATE inventory_movements SET reason_code = 'import' WHERE type = 'adjustment' AND reason = 'استيراد'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_reason_code_on_adjustments');
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movements_reason_code_known');
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropColumn('reason_code');
        });
    }
};
