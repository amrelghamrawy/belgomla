<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\CreateCategory;
use App\Modules\Catalog\Data\CategoryData;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('CreateCategory creates a root category with sort order 0 and a system audit record', function (): void {
    $category = app(CreateCategory::class)->handle(new CategoryData('أقلام', null));

    $log = AuditLog::query()->sole();

    expect($category->sort_order)->toBe(0)
        ->and($category->path)->toBe("/{$category->id}/")
        ->and($log->action)->toBe('category.created')
        ->and($log->actor_type->value)->toBe('system')
        ->and($log->new_values)->toEqual(['name' => 'أقلام', 'parent_id' => null, 'sort_order' => 0, 'image_path' => null]);
});

test('CreateCategory stores the image on the public disk', function (): void {
    $category = app(CreateCategory::class)->handle(new CategoryData('أقلام', null, image: UploadedFile::fake()->image('a.png')));

    Storage::disk('public')->assertExists($category->image_path);
});

test('CreateCategory rejects an unknown parent and writes nothing', function (): void {
    expect(fn () => app(CreateCategory::class)->handle(new CategoryData('x', 999_999)))
        ->toThrow(InvalidCatalogInput::class);

    expect(Category::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});
