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
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('kind', 20)->default('general'); // books | stationery | toys | general (D68)
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_kind_check CHECK (kind IN ('books', 'stationery', 'toys', 'general'))");

        // Existing school-book stores sold books only: their whole tree is the books section.
        DB::statement("UPDATE categories SET kind = 'books' WHERE tenant_id IN (SELECT id FROM tenants WHERE vertical = 'school-books')");
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
