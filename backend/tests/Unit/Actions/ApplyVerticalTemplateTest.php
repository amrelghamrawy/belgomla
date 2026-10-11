<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\ApplyVerticalTemplate;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-P09 the school-books template creates the 7 filterable select fields in facet order', function (): void {
    $created = app(ApplyVerticalTemplate::class)->handle(Vertical::SchoolBooks);

    $fields = ProductFieldDefinition::query()->orderBy('filter_order')->get();

    expect($created)->toBe(['grade', 'subject', 'publisher', 'term', 'track', 'edition_type', 'academic_year'])
        ->and($fields->pluck('key')->all())->toBe($created)
        ->and($fields->every(fn (ProductFieldDefinition $f): bool => $f->type === FieldType::Select && $f->is_filterable && $f->kind === CategoryKind::Books))->toBeTrue()
        ->and(ProductFieldOption::query()->where('label', 'أولى إعدادي')->exists())->toBeTrue()
        ->and(ProductFieldOption::query()->where('label', 'الأضواء')->exists())->toBeTrue();
});

test('BR-P09 applying a template twice creates nothing new', function (): void {
    app(ApplyVerticalTemplate::class)->handle(Vertical::SchoolBooks);
    $options = ProductFieldOption::query()->count();

    $second = app(ApplyVerticalTemplate::class)->handle(Vertical::SchoolBooks);

    expect($second)->toBe([])
        ->and(ProductFieldDefinition::query()->count())->toBe(7)
        ->and(ProductFieldOption::query()->count())->toBe($options)
        ->and(AuditLog::query()->where('action', 'catalog.template_applied')->count())->toBe(1);
});

test('BR-P09 a template fills missing options of an existing field without touching the merchant edits', function (): void {
    $term = ProductFieldDefinition::factory()->select()->create(['key' => 'term', 'label' => 'الفصل الدراسي']);
    ProductFieldOption::factory()->for($term, 'definition')->create(['label' => 'الترم الأول']);

    app(ApplyVerticalTemplate::class)->handle(Vertical::SchoolBooks);

    expect($term->refresh()->label)->toBe('الفصل الدراسي')
        ->and($term->options()->pluck('label')->all())->toBe(['الترم الأول', 'الترم الثاني', 'كامل العام']);
});

test('the none vertical is a no-op', function (): void {
    expect(app(ApplyVerticalTemplate::class)->handle(Vertical::None))->toBe([])
        ->and(ProductFieldDefinition::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('stationery and hardware templates create their fields', function (Vertical $vertical, array $keys): void {
    expect(app(ApplyVerticalTemplate::class)->handle($vertical))->toBe($keys);
})->with([
    'stationery' => [Vertical::Stationery, ['brand', 'color', 'size']],
    'hardware' => [Vertical::Hardware, ['brand', 'material', 'size']],
]);
