<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2 Task 06: one row per catalog import run. `uuid` is the preview token of the uploaded file; `batch_id` is the
| audit batch every change of the run is written under; `errors` keeps the first row errors (capped).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('uuid');
            $table->string('file_name', 255);
            $table->string('status', 20)->default('queued');
            $table->integer('total')->default(0);
            $table->integer('processed')->default(0);
            $table->integer('created')->default(0);
            $table->integer('updated')->default(0);
            $table->integer('skipped')->default(0);
            $table->integer('failed')->default(0);
            $table->jsonb('errors')->default('[]');
            $table->uuid('batch_id');
            $table->foreignId('created_by')->nullable()->constrained('tenant_users'); // null = system
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'uuid']);
        });

        DB::statement("ALTER TABLE imports ADD CONSTRAINT imports_status_known CHECK (status IN ('queued', 'running', 'done', 'failed'))");
        DB::statement('ALTER TABLE imports ADD CONSTRAINT imports_counts_non_negative CHECK (total >= 0 AND processed >= 0 AND created >= 0 AND updated >= 0 AND skipped >= 0 AND failed >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
