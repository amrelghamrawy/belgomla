<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\ApplyVerticalTemplate;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\RelationType;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\TenantSetting;
use Database\Seeders\StorefrontDemoSeeder;

beforeEach(function (): void {
    $this->inTenant(fn (): array => app(ApplyVerticalTemplate::class)->handle(Vertical::SchoolBooks));
});

test('the demo catalog seeds books, categories, stock and the store settings', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    [$products, $comingSoon, $categories, $stocked] = $this->inTenant(fn (): array => [
        Product::query()->count(),
        Product::query()->where('status', ProductStatus::ComingSoon)->count(),
        Category::query()->count(),
        SkuInventory::query()->where('on_hand', 1000)->count(),
    ]);
    $settings = TenantSetting::query()->whereKey($this->tenant->id)->sole();

    expect($products)->toBe(34)
        ->and($comingSoon)->toBe(1)
        ->and($categories)->toBe(6)
        ->and($stocked)->toBe(33)
        ->and($settings->mov_enabled)->toBeTrue()
        ->and($settings->mov_amount)->toBe('500.00')
        ->and($settings->whatsapp)->toBe('+201001234567');
});

test('the final revision edition is linked to its main book', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    $relation = $this->inTenant(fn (): ProductRelation => ProductRelation::query()->with('product', 'relatedProduct')->sole());

    expect($relation->type)->toBe(RelationType::RevisionOf)
        ->and($relation->product->name)->toBe('المراجعة النهائية '.$relation->relatedProduct->name);
});

test('the store lists the seeded books with the grade facet', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    $response = $this->tenantJson('GET', '/api/store/filters')->assertOk();

    expect($response->json('data.0.key'))->toBe('grade')
        ->and($this->tenantJson('GET', '/api/store/products?per_page=24')->json('meta.total'))->toBe(34);
});

test('a database with other test data still gets the demo catalog, reusing its units', function (): void {
    $this->catalogBasics();

    $this->seed(StorefrontDemoSeeder::class);

    expect($this->inTenant(fn (): array => [Product::query()->count(), Unit::query()->count()]))->toBe([34, 3]);
});

test('seeding twice changes nothing', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    $this->seed(StorefrontDemoSeeder::class);

    expect($this->inTenant(fn (): int => Product::query()->count()))->toBe(34);
});

test('the books read like the publisher describes them, the revision edition as an exam review', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    [$book, $revision] = $this->inTenant(fn (): array => [
        Product::query()->where('name', 'الأضواء عربي أولى إعدادي')->value('description'),
        Product::query()->where('name', 'like', 'المراجعة النهائية%')->value('description'),
    ]);

    expect($book)->toBe('كتاب الأضواء في مادة عربي لطلاب أولى إعدادي، الترم الأول، طبعة 2026/2027 مطابقة للمنهج الجديد. يغطي النحو والقراءة والنصوص والقصة والتعبير، مع شرح مبسّط لكل درس، وتدريبات متدرجة من السهل للصعب، ونماذج امتحانات بالإجابات.')
        ->and($revision)->toStartWith('مراجعة ليلة الامتحان في مادة عربي لطلاب أولى إعدادي');
});
