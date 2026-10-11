<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array SCHOOL_BOOK_KEYS = ['grade', 'subject', 'publisher', 'term', 'track', 'edition_type', 'academic_year'];

    public function up(): void
    {
        Schema::table('product_field_definitions', function (Blueprint $table): void {
            $table->string('kind', 20)->nullable(); // null = every section; else books | stationery | toys | general (D69)
        });

        DB::statement("ALTER TABLE product_field_definitions ADD CONSTRAINT product_field_definitions_kind_check CHECK (kind IS NULL OR kind IN ('books', 'stationery', 'toys', 'general'))");

        // The school-books template fields of existing school-book stores describe books only.
        DB::table('product_field_definitions')
            ->whereIn('tenant_id', DB::table('tenants')->where('vertical', 'school-books')->select('id'))
            ->whereIn('key', self::SCHOOL_BOOK_KEYS)
            ->update(['kind' => 'books']);
    }

    public function down(): void
    {
        Schema::table('product_field_definitions', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
