<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V3 Task 02 (D109, BR-P16): the section of a root category stops being the fixed CategoryKind enum and becomes a
| tenant row. Backfill, per tenant, with no behaviour change:
| 1. sections = the active sections of the tenant's vertical (key, name, reference price label, icon) + one fallback
|    «عام» (key `general`, is_fallback, D109 c);
| 2. categories.kind and product_field_definitions.kind → the tenant's section with that key (a kind with no such
|    section → the fallback); a null field kind stays null (every section, D69);
| 3. product_field_definitions.template_id → the vertical's template with the same key (D110 b), when there is one.
| The `key` of a section is its machine name (D109 default, 06): copied from the vertical section, `general` for
| the fallback; it carries the old `kind` value in the API until V3 Task 03.
| down() writes the keys back into `kind` (a key that is no old kind → general).
*/
return new class extends Migration
{
    private const array OLD_KINDS = ['books', 'stationery', 'toys', 'general'];

    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('vertical_section_id')->nullable()->constrained('vertical_sections');
            $table->string('key', 40);
            $table->string('name', 80);
            $table->string('reference_price_label', 40)->default('سعر القطاعي');
            $table->string('icon', 40)->nullable();
            $table->boolean('is_fallback')->default(false);
            $table->string('status', 20)->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
            $table->unique(['tenant_id', 'name']);
        });
        DB::statement("ALTER TABLE sections ADD CONSTRAINT sections_status_known CHECK (status IN ('active', 'archived'))");
        DB::statement('ALTER TABLE sections ADD CONSTRAINT sections_fallback_active CHECK (NOT is_fallback OR status = \'active\')');
        DB::statement('CREATE UNIQUE INDEX sections_one_fallback ON sections (tenant_id) WHERE is_fallback');
        DB::statement('CREATE UNIQUE INDEX sections_one_per_vertical_section ON sections (tenant_id, vertical_section_id) WHERE vertical_section_id IS NOT NULL');

        Schema::table('categories', function (Blueprint $table): void {
            $table->foreignId('section_id')->nullable()->constrained('sections');
        });
        Schema::table('product_field_definitions', function (Blueprint $table): void {
            $table->foreignId('section_id')->nullable()->constrained('sections');
            $table->foreignId('template_id')->nullable()->constrained('vertical_field_templates')->nullOnDelete();
        });

        foreach (DB::table('tenants')->orderBy('id')->get(['id', 'vertical_id']) as $tenant) {
            $this->backfill((int) $tenant->id, (int) $tenant->vertical_id);
        }

        DB::statement('ALTER TABLE categories ALTER COLUMN section_id SET NOT NULL');
        DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_kind_check');
        DB::statement('ALTER TABLE product_field_definitions DROP CONSTRAINT product_field_definitions_kind_check');
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
        Schema::table('product_field_definitions', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('kind', 20)->default('general');
        });
        Schema::table('product_field_definitions', function (Blueprint $table): void {
            $table->string('kind', 20)->nullable();
        });

        $known = "'".implode("', '", self::OLD_KINDS)."'";
        DB::statement("UPDATE categories SET kind = CASE WHEN s.key IN ({$known}) THEN s.key ELSE 'general' END FROM sections s WHERE s.id = categories.section_id");
        DB::statement("UPDATE product_field_definitions SET kind = CASE WHEN s.key IN ({$known}) THEN s.key ELSE 'general' END FROM sections s WHERE s.id = product_field_definitions.section_id");
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_kind_check CHECK (kind IN ({$known}))");
        DB::statement("ALTER TABLE product_field_definitions ADD CONSTRAINT product_field_definitions_kind_check CHECK (kind IS NULL OR kind IN ({$known}))");

        Schema::table('product_field_definitions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('template_id');
            $table->dropConstrainedForeignId('section_id');
        });
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('section_id');
        });
        Schema::dropIfExists('sections');
    }

    private function backfill(int $tenantId, int $verticalId): void
    {
        $now = now();
        $sections = DB::table('vertical_sections')->where('vertical_id', $verticalId)->where('status', 'active')->orderBy('sort_order')->orderBy('id')->get();
        $ids = [];

        foreach ($sections as $index => $section) {
            $ids[(string) $section->key] = DB::table('sections')->insertGetId([
                'tenant_id' => $tenantId, 'vertical_section_id' => $section->id, 'key' => $section->key, 'name' => $section->name,
                'reference_price_label' => $section->reference_price_label, 'icon' => $section->icon, 'is_fallback' => false,
                'status' => 'active', 'sort_order' => $index + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $fallback = DB::table('sections')->insertGetId([
            'tenant_id' => $tenantId, 'vertical_section_id' => null, 'key' => 'general', 'name' => 'عام',
            'reference_price_label' => 'سعر القطاعي', 'icon' => 'package', 'is_fallback' => true,
            'status' => 'active', 'sort_order' => count($ids) + 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (self::OLD_KINDS as $kind) {
            $target = $ids[$kind] ?? $fallback;
            DB::table('categories')->where('tenant_id', $tenantId)->where('kind', $kind)->update(['section_id' => $target]);
            DB::table('product_field_definitions')->where('tenant_id', $tenantId)->where('kind', $kind)->update(['section_id' => $target]);
        }

        DB::statement(
            'UPDATE product_field_definitions d SET template_id = t.id FROM vertical_field_templates t WHERE d.tenant_id = ? AND t.vertical_id = ? AND t.key = d.key',
            [$tenantId, $verticalId],
        );
    }
};
