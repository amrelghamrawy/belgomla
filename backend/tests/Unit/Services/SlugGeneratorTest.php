<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\SlugGenerator;

test('slugify keeps arabic letters and digits and joins words with hyphens', function (string $input, string $expected): void {
    expect(app(SlugGenerator::class)->slugify($input))->toBe($expected);
})->with([
    'arabic words' => ['علوم أولى إعدادي', 'علوم-أولى-إعدادي'],
    'tashkeel and tatweel are dropped' => ['عُلـــوم', 'علوم'],
    'symbols collapse' => ['قلم / أزرق (جاف)!!', 'قلم-أزرق-جاف'],
    'latin is lower-cased' => ['Blue PEN 2026', 'blue-pen-2026'],
    'nothing usable falls back' => ['!!!', 'item'],
]);

test('unique appends -2, -3 on collision within the tenant', function (): void {
    $this->inTenant(function (): void {
        Product::factory()->create(['slug' => 'قلم', 'name' => 'x']);
        Product::factory()->create(['slug' => 'قلم-2', 'name' => 'y']);
    });

    $slug = $this->inTenant(fn () => app(SlugGenerator::class)->unique(Product::class, 'قلم', 220));

    expect($slug)->toBe('قلم-3');
});

test('unique ignores the row being renamed', function (): void {
    $category = $this->inTenant(fn () => Category::factory()->create(['name' => 'إعدادي']));

    $slug = $this->inTenant(fn () => app(SlugGenerator::class)->unique(Category::class, 'إعدادي', 120, $category->id));

    expect($slug)->toBe('إعدادي');
});

test('unique truncates long names to leave room for a suffix', function (): void {
    $slug = $this->inTenant(fn () => app(SlugGenerator::class)->unique(Category::class, str_repeat('ب', 200), 120));

    expect(mb_strlen($slug))->toBe(114);
});
