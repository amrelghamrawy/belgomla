<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\ApplyVerticalTemplate;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\RelationType;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\TenantSetting;
use Database\Seeders\StorefrontDemoSeeder;

beforeEach(function (): void {
    $this->inTenant(fn (): array => app(ApplyVerticalTemplate::class)->handle(Vertical::SchoolBooks));
});

test('the demo catalog seeds books, supplies, toys, categories, stock and the store settings', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    [$products, $comingSoon, $categories, $stocked] = $this->inTenant(fn (): array => [
        Product::query()->count(),
        Product::query()->where('status', ProductStatus::ComingSoon)->count(),
        Category::query()->count(),
        SkuInventory::query()->where('on_hand', 1000)->count(),
    ]);
    $settings = TenantSetting::query()->whereKey($this->tenant->id)->sole();

    expect($products)->toBe(44)
        ->and($comingSoon)->toBe(1)
        ->and($categories)->toBe(11)
        ->and($stocked)->toBe(43)
        ->and($settings->mov_enabled)->toBeTrue()
        ->and($settings->mov_amount)->toBe('500.00')
        ->and($settings->whatsapp)->toBe('+201001234567');
});

test('D68 the demo catalog has the three sections as roots, each subtree of its kind', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    $roots = $this->inTenant(fn (): array => Category::query()->whereNull('parent_id')->orderBy('sort_order')->get()->map(fn (Category $c): array => [$c->name, $c->kind->value])->all());
    $grade = $this->inTenant(fn (): Category => Category::query()->where('name', 'أولى إعدادي')->sole());

    expect($roots)->toBe([['كتب خارجية', 'books'], ['أدوات دراسية', 'stationery'], ['ألعاب', 'toys']])
        ->and($grade->kind)->toBe(CategoryKind::Books);
});

test('D69 the demo fields are bound to their sections, so a toys category offers only the age facet', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    $toys = $this->inTenant(fn (): Category => Category::query()->where('name', 'ألعاب')->sole());
    $kinds = $this->inTenant(fn (): array => ProductFieldDefinition::query()->orderBy('key')->pluck('kind', 'key')->map(fn (?CategoryKind $k): ?string => $k?->value)->all());

    expect($kinds)->toBe([
        'academic_year' => 'books', 'age' => 'toys', 'brand' => 'stationery', 'color' => 'stationery', 'edition_type' => 'books',
        'grade' => 'books', 'publisher' => 'books', 'size' => 'stationery', 'subject' => 'books', 'term' => 'books', 'track' => 'books',
    ])
        ->and($this->tenantJson('GET', "/api/store/filters?category={$toys->slug}")->assertOk()->json('data.*.key'))->toBe(['age']);
});

test('D68 toys carry a retail price or none, and the store names their section', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    $response = $this->tenantJson('GET', '/api/store/products?q=شطرنج')->assertOk();

    expect($response->json('data.0.category.kind'))->toBe('toys')
        ->and($response->json('data.0.sku.cover_price'))->toBeNull()
        ->and($response->json('data.0.custom_fields.*.key'))->toBe(['age']);
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
        ->and($this->tenantJson('GET', '/api/store/products?per_page=24')->json('meta.total'))->toBe(44);
});

test('a database with other test data still gets the demo catalog, reusing its units', function (): void {
    $this->catalogBasics();

    $this->seed(StorefrontDemoSeeder::class);

    expect($this->inTenant(fn (): array => [Product::query()->count(), Unit::query()->count()]))->toBe([44, 3]);
});

test('seeding twice changes nothing', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    $this->seed(StorefrontDemoSeeder::class);

    expect($this->inTenant(fn (): array => [Product::query()->count(), Category::query()->count(), ProductFieldDefinition::query()->count()]))->toBe([44, 11, 11]);
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
