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
use App\Modules\Tenancy\Models\TenantSetting;
use Database\Seeders\StorefrontDemoSeeder;

beforeEach(function (): void {
    $this->inTenant(fn (): array => app(ApplyVerticalTemplate::class)->handle($this->tenant->vertical_id));
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

    expect($products)->toBe(46)
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
        'grade' => 'books', 'material' => null, 'publisher' => 'books', 'size' => 'stationery', 'subject' => 'books', 'term' => 'books', 'track' => 'books',
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
        ->and($this->tenantJson('GET', '/api/store/products?per_page=24')->json('meta.total'))->toBe(46);
});

test('a database with other test data still gets the demo catalog, reusing its units', function (): void {
    $this->catalogBasics();

    $this->seed(StorefrontDemoSeeder::class);

    expect($this->inTenant(fn (): array => [Product::query()->count(), Unit::query()->count()]))->toBe([46, 3]);
});

test('seeding twice changes nothing', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    $this->seed(StorefrontDemoSeeder::class);

    expect($this->inTenant(fn (): array => [Product::query()->count(), Category::query()->count(), ProductFieldDefinition::query()->count()]))->toBe([46, 11, 12]); // 12 fields: the 11 libraries templates (BR-P09) + the toys-only age field
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

test('D91 the A4 notebook sells by the piece and by the carton of 24, the piece by default', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    $units = $this->inTenant(fn (): array => App\Modules\Catalog\Models\SkuUnit::query()
        ->with('unit')
        ->whereHas('sku', fn (Illuminate\Database\Eloquent\Builder $sku): Illuminate\Database\Eloquent\Builder => $sku->where('code', 'ST-006'))
        ->orderBy('sort_order')
        ->get()
        ->map(fn (App\Modules\Catalog\Models\SkuUnit $unit): array => [$unit->unit->name, $unit->conversion_factor, $unit->base_price, $unit->cover_price, $unit->moq, $unit->is_default])
        ->all());

    expect($units)->toBe([
        ['قطعة', 1, '45.00', '60.00', 6, true],
        ['كرتونة', 24, '972.00', '1440.00', 1, false],
    ]);
});

test('D93 the demo catalog has a grade set of four الأضواء books of أولى إعدادي at 10% off their sum', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    $set = $this->inTenant(fn (): App\Modules\Catalog\Models\Sku => App\Modules\Catalog\Models\Sku::query()->with(['product', 'defaultUnit', 'bundleItems.componentUnit.sku', 'inventory'])->where('code', 'SET-DEMO-1')->sole());

    expect($set->product->type)->toBe(App\Modules\Catalog\Enums\ProductType::Bundle)
        ->and($set->product->name)->toBe('باقة الأضواء أولى إعدادي')
        ->and($set->product->status)->toBe(ProductStatus::Active)
        ->and($set->bundleItems->map(fn (App\Modules\Catalog\Models\BundleItem $item): array => [$item->componentUnit->sku->code, $item->quantity])->all())->toBe([['BK-001', 1], ['BK-007', 1], ['BK-010', 1], ['BK-013', 1]])
        ->and($set->defaultUnit?->base_price)->toBe('367.20')
        ->and($set->inventory)->toBeNull();
});

test('BR-P14 the demo catalog has a highlighter in three colours, each its own sku with its own stock', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    $skus = $this->inTenant(fn () => Product::query()->where('name', 'قلم هايلايتر فابر كاستل')->sole()->skus()->with('inventory')->get());

    expect($skus->pluck('code')->all())->toBe(['ST-007-1', 'ST-007-2', 'ST-007-3'])
        ->and($skus->pluck('name')->all())->toBe(['أصفر', 'أخضر', 'وردي'])
        ->and($skus->pluck('inventory.on_hand')->all())->toBe([600, 400, 200]);
});

test('D26 every demo product gets a drawn cover as its main image', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    [$products, $images] = $this->inTenant(fn (): array => [
        Product::query()->count(),
        App\Modules\Catalog\Models\ProductImage::query()->where('is_main', true)->pluck('path')->all(),
    ]);

    expect($images)->toHaveCount($products);
    Illuminate\Support\Facades\Storage::disk('public')->assertExists($images);
});

test('BR-O14 the Coming Soon demo book takes pre-orders, expected in three weeks', function (): void {
    $this->seed(StorefrontDemoSeeder::class);

    $book = $this->inTenant(fn (): Product => Product::query()->where('status', ProductStatus::ComingSoon)->sole());

    expect($book->preorder_enabled)->toBeTrue()
        ->and($book->expected_at?->toDateString())->toBe(now()->addDays(21)->toDateString());
});
