<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V3 Task 01 (D108, D110, BR-P09, BR-P16): the store vertical and its default sections and field templates become
| platform data. Until now they were the Vertical enum on tenants.vertical and the PHP files database/verticals/*.php.
|
| Data: one vertical «مكتبات» (libraries) with the sections books, stationery, toys and the fields of the three old
| files, copied here verbatim because the files are deleted in the same commit:
| - school-books.php → section books; stationery.php → section stationery;
| - hardware.php → only `material` (no section): its brand and size collide by key with the stationery ones, which
|   are kept. Fields already copied into hardware tenants are tenant rows and are not touched.
| Every tenant, whatever its old vertical (school-books, stationery, hardware, none), moves to libraries (D108 c).
|
| down() restores the schema only: tenants.vertical comes back as 'none' for every tenant (one-way on data).
*/
return new class extends Migration
{
    /** @var list<array{key: string, name: string, reference_price_label: string, icon: string}> */
    private const array SECTIONS = [
        ['key' => 'books', 'name' => 'كتب خارجية', 'reference_price_label' => 'سعر الغلاف', 'icon' => 'book-open'],
        ['key' => 'stationery', 'name' => 'أدوات مكتبية', 'reference_price_label' => 'سعر القطاعي', 'icon' => 'pencil'],
        ['key' => 'toys', 'name' => 'ألعاب', 'reference_price_label' => 'سعر القطاعي', 'icon' => 'puzzle'],
    ];

    /** @var list<array{key: string, label: string, section: string|null, filter_order: int, sort_order: int, options: list<string>}> */
    private const array FIELDS = [
        ['key' => 'grade', 'label' => 'السنة الدراسية', 'section' => 'books', 'filter_order' => 1, 'sort_order' => 1, 'options' => [
            'KG1', 'KG2',
            'أولى ابتدائي', 'ثانية ابتدائي', 'ثالثة ابتدائي', 'رابعة ابتدائي', 'خامسة ابتدائي', 'سادسة ابتدائي',
            'أولى إعدادي', 'ثانية إعدادي', 'ثالثة إعدادي',
            'أولى ثانوي', 'ثانية ثانوي', 'ثالثة ثانوي',
        ]],
        ['key' => 'subject', 'label' => 'المادة', 'section' => 'books', 'filter_order' => 2, 'sort_order' => 2, 'options' => [
            'عربي', 'إنجليزي', 'رياضيات', 'علوم', 'دراسات', 'دين', 'فرنساوي',
            'فيزياء', 'كيمياء', 'أحياء', 'فلسفة', 'تاريخ', 'جغرافيا', 'حاسب آلي',
        ]],
        ['key' => 'publisher', 'label' => 'الشركة', 'section' => 'books', 'filter_order' => 3, 'sort_order' => 3, 'options' => [
            'الأضواء', 'المعلم', 'كراسة الأول', 'سلاح التلميذ', 'الامتحان', 'بت بايت', 'جيم',
        ]],
        ['key' => 'term', 'label' => 'الترم', 'section' => 'books', 'filter_order' => 4, 'sort_order' => 4, 'options' => ['الترم الأول', 'الترم الثاني', 'كامل العام']],
        ['key' => 'track', 'label' => 'المسار', 'section' => 'books', 'filter_order' => 5, 'sort_order' => 5, 'options' => ['عربي', 'لغات']],
        ['key' => 'edition_type', 'label' => 'نوع النسخة', 'section' => 'books', 'filter_order' => 6, 'sort_order' => 6, 'options' => [
            'الكتاب الأساسي', 'المراجعة النهائية', 'كتاب الامتحانات', 'ملحق المراجعة والامتحانات',
        ]],
        ['key' => 'academic_year', 'label' => 'العام الدراسي', 'section' => 'books', 'filter_order' => 7, 'sort_order' => 7, 'options' => ['2025/2026', '2026/2027']],
        ['key' => 'brand', 'label' => 'الماركة', 'section' => 'stationery', 'filter_order' => 1, 'sort_order' => 1, 'options' => []],
        ['key' => 'color', 'label' => 'اللون', 'section' => 'stationery', 'filter_order' => 2, 'sort_order' => 2, 'options' => [
            'أزرق', 'أسود', 'أحمر', 'أخضر', 'أبيض', 'ألوان متعددة',
        ]],
        ['key' => 'size', 'label' => 'المقاس', 'section' => 'stationery', 'filter_order' => 3, 'sort_order' => 3, 'options' => []],
        ['key' => 'material', 'label' => 'الخامة', 'section' => null, 'filter_order' => 2, 'sort_order' => 2, 'options' => []],
    ];

    public function up(): void
    {
        $this->createTables();
        $verticalId = $this->seedLibraries();

        Schema::table('tenants', function (Blueprint $table): void {
            $table->foreignId('vertical_id')->nullable()->constrained('verticals');
        });
        DB::table('tenants')->update(['vertical_id' => $verticalId]);
        DB::statement('ALTER TABLE tenants ALTER COLUMN vertical_id SET NOT NULL');
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('vertical');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('vertical', 30)->default('none');
        });
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vertical_id');
        });
        Schema::dropIfExists('vertical_field_template_options');
        Schema::dropIfExists('vertical_field_templates');
        Schema::dropIfExists('vertical_sections');
        Schema::dropIfExists('verticals');
    }

    private function createTables(): void
    {
        Schema::create('verticals', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->string('status', 20)->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE verticals ADD CONSTRAINT verticals_status_known CHECK (status IN ('active', 'archived'))");

        Schema::create('vertical_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vertical_id')->constrained('verticals')->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('name', 80);
            $table->string('reference_price_label', 40)->default('سعر القطاعي');
            $table->string('icon', 40)->nullable();
            $table->string('status', 20)->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['vertical_id', 'key']);
        });
        DB::statement("ALTER TABLE vertical_sections ADD CONSTRAINT vertical_sections_status_known CHECK (status IN ('active', 'archived'))");

        Schema::create('vertical_field_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vertical_id')->constrained('verticals')->cascadeOnDelete();
            $table->foreignId('vertical_section_id')->nullable()->constrained('vertical_sections');
            $table->string('key', 40);
            $table->string('label', 100);
            $table->string('type', 20);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->integer('filter_order')->default(0);
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['vertical_id', 'key']);
        });
        DB::statement("ALTER TABLE vertical_field_templates ADD CONSTRAINT vertical_field_templates_status_known CHECK (status IN ('active', 'archived'))");
        DB::statement("ALTER TABLE vertical_field_templates ADD CONSTRAINT vertical_field_templates_type_known CHECK (type IN ('text', 'number', 'select', 'boolean'))");

        Schema::create('vertical_field_template_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('vertical_field_templates')->cascadeOnDelete();
            $table->string('label', 100);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['template_id', 'label']);
        });
    }

    private function seedLibraries(): int
    {
        $now = now();
        $verticalId = DB::table('verticals')->insertGetId([
            'key' => 'libraries', 'name' => 'مكتبات', 'status' => 'active', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $sectionIds = [];

        foreach (self::SECTIONS as $index => $section) {
            $sectionIds[$section['key']] = DB::table('vertical_sections')->insertGetId([
                ...$section, 'vertical_id' => $verticalId, 'status' => 'active', 'sort_order' => $index + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (self::FIELDS as $field) {
            $templateId = DB::table('vertical_field_templates')->insertGetId([
                'vertical_id' => $verticalId,
                'vertical_section_id' => $field['section'] === null ? null : $sectionIds[$field['section']],
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => 'select',
                'is_required' => false,
                'is_filterable' => true,
                'filter_order' => $field['filter_order'],
                'sort_order' => $field['sort_order'],
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($field['options'] as $index => $label) {
                DB::table('vertical_field_template_options')->insert([
                    'template_id' => $templateId, 'label' => $label, 'sort_order' => $index + 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        return $verticalId;
    }
};
