<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Actions\AddProductImage;
use App\Modules\Catalog\Actions\AddProductRelation;
use App\Modules\Catalog\Actions\ApplyVerticalTemplate;
use App\Modules\Catalog\Actions\CreateBundle;
use App\Modules\Catalog\Actions\CreateCategory;
use App\Modules\Catalog\Actions\CreateFieldDefinition;
use App\Modules\Catalog\Actions\CreateFieldOption;
use App\Modules\Catalog\Actions\CreateProduct;
use App\Modules\Catalog\Actions\CreateUnit;
use App\Modules\Catalog\Data\BundleComponentData;
use App\Modules\Catalog\Data\BundleData;
use App\Modules\Catalog\Data\CategoryData;
use App\Modules\Catalog\Data\FieldDefinitionData;
use App\Modules\Catalog\Data\PreorderData;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\ProductOptionData;
use App\Modules\Catalog\Data\ProductOptionValueData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\RelationType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\Section;
use App\Modules\Catalog\Queries\CategoryTreeQuery;
use App\Modules\Catalog\Queries\FieldDefinitionsQuery;
use App\Modules\Catalog\Queries\UnitsQuery;
use App\Modules\Catalog\Services\VariantGenerator;
use App\Modules\Pricing\Data\BundlePricingData;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Modules\Tenancy\Actions\UpdateTenantSettings;
use App\Modules\Tenancy\Data\TenantSettingsData;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/**
 * Local development and Playwright catalog for the `alnoor` tenant (tasks/9), or the store `tenant:demo` creates: store contacts, a minimum order
 * value of 500 and the three sections of D68. Books (كتب خارجية): a stage/grade tree, 34 books with
 * grade/subject/publisher facets, cover and wholesale prices, a few tiers and carton MOQs, stock on every
 * book, one coming-soon book, one final-revision edition linked to its main book and a grade set (a bundle of four
 * الأضواء books of أولى إعدادي at 10% off, SET-DEMO-1, D93). School supplies
 * (أدوات دراسية): pens and notebooks with the stationery template's brand/color/size facets and a retail
 * price. Toys (ألعاب): a few items with an age facet, some without a retail price. Everything goes through
 * the module actions, like a merchant would. Runs once.
 */
final class StorefrontDemoSeeder extends Seeder
{
    private const array SUBJECTS = ['عربي', 'إنجليزي', 'رياضيات', 'علوم', 'دراسات'];

    private const array PUBLISHERS = ['الأضواء', 'المعلم', 'سلاح التلميذ'];

    private const array BRANDS = ['روتو', 'بريما', 'فابر كاستل'];

    private const array SIZES = ['A4', 'A5'];

    private const array AGES = ['3+', '6+', '9+'];

    /** @var array<string, array<string, int>> option id by field key and label */
    private array $options = [];

    public function run(
        TenantContext $context,
        CategoryTreeQuery $tree,
        UnitsQuery $existingUnits,
        FieldDefinitionsQuery $fields,
        UpdateTenantSettings $settings,
        ApplyVerticalTemplate $templates,
        CreateFieldDefinition $fieldDefinitions,
        CreateFieldOption $fieldOptions,
        CreateUnit $units,
        CreateCategory $categories,
        CreateProduct $products,
        AddProductRelation $relations,
        CreateBundle $bundles,
        AddProductImage $images,
        string $subdomain = 'alnoor',
    ): void {
        $tenant = Tenant::query()->where('subdomain', $subdomain)->firstOrFail();

        $context->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $tree, $existingUnits, $fields, $settings, $templates, $fieldDefinitions, $fieldOptions, $units, $categories, $products, $relations, $bundles, $images): void {
            // The demo tree's books root marks a seeded catalog; a dev database may hold other test data.
            if ($tree->forDashboard()->contains('name', 'كتب خارجية')) {
                return;
            }

            $this->sectionFields($tenant->vertical_id, $fields, $templates, $fieldDefinitions, $fieldOptions);
            $settings->handle($tenant, TenantSetting::query()->whereKey($tenant->id)->firstOrFail(), $this->storeSettings());

            $known = [];

            foreach ($existingUnits->get() as $unit) {
                $known[$unit->name] = $unit->id;
            }

            $copy = $known['نسخة'] ?? $units->handle('نسخة')->id;
            $carton = $known['كرتونة'] ?? $units->handle('كرتونة')->id;
            $piece = $known['قطعة'] ?? $units->handle('قطعة')->id;

            $this->books($categories, $products, $relations, $bundles, $copy, $carton);
            $this->supplies($categories, $products, $piece, $carton);
            $this->toys($categories, $products, $piece);
            $this->covers($images);
        }));
    }

    /**
     * D26, D61: a drawn cover for every product (a colour per subject or section, the SKU code on a band), so the
     * store and the product gallery have pictures. Skipped without GD.
     */
    private function covers(AddProductImage $images): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            return;
        }

        $palette = [[0x1E, 0x6F, 0xB8], [0x2E, 0x8B, 0x57], [0xC0, 0x39, 0x2B], [0x8E, 0x44, 0xAD], [0xD3, 0x84, 0x1A], [0x16, 0xA0, 0x85], [0x34, 0x49, 0x5E]];

        foreach (Product::query()->with('skus')->orderBy('id')->get() as $index => $product) {
            $subject = array_find_key(self::SUBJECTS, fn (string $name): bool => str_contains($product->name, $name));
            $code = $product->skus->first()->code ?? '';
            $file = $this->cover($palette[($subject ?? $index) % count($palette)], $code);
            $images->handle($product, new UploadedFile($file, "{$code}.png", 'image/png', null, true));
            @unlink($file);
        }
    }

    /**
     * A 600×800 PNG: the colour, a lighter panel and circle, and a dark band with the code.
     *
     * @param  array{int, int, int}  $rgb
     */
    private function cover(array $rgb, string $code): string
    {
        $image = imagecreatetruecolor(600, 800) ?: throw new RuntimeException('GD could not create an image.');
        [$r, $g, $b] = $rgb;
        $tint = fn (float $mix): int => (int) imagecolorallocate($image, $this->channel($r + (255 - $r) * $mix), $this->channel($g + (255 - $g) * $mix), $this->channel($b + (255 - $b) * $mix));
        $shade = (int) imagecolorallocate($image, $this->channel($r * 0.55), $this->channel($g * 0.55), $this->channel($b * 0.55));

        imagefill($image, 0, 0, $tint(0.0));
        imagefilledrectangle($image, 50, 60, 550, 330, $tint(0.85));
        imagefilledellipse($image, 300, 500, 260, 260, $tint(0.35));
        imagefilledellipse($image, 300, 500, 150, 150, $tint(0.7));
        imagefilledrectangle($image, 0, 690, 600, 800, $shade);
        imagestring($image, 5, 300 - (int) (mb_strlen($code) * imagefontwidth(5) / 2), 737, $code, (int) imagecolorallocate($image, 255, 255, 255));

        $path = storage_path('framework/cache/cover-'.Str::random(16).'.png');

        if (! imagepng($image, $path, 9)) {
            throw new RuntimeException('Could not write a cover.');
        }

        return $path;
    }

    /** @return int<0, 255> */
    private function channel(float $value): int
    {
        return max(0, min(255, (int) $value));
    }

    /**
     * BR-P09, D69: the vertical's templates (books and stationery fields) are applied at tenant creation and again
     * here (idempotent, for a store made without CreateTenant), its empty brand/size options filled, and a toys-only age field created.
     */
    private function sectionFields(int $verticalId, FieldDefinitionsQuery $fields, ApplyVerticalTemplate $templates, CreateFieldDefinition $fieldDefinitions, CreateFieldOption $fieldOptions): void
    {
        $templates->handle($verticalId);

        $definitions = $fields->get()->keyBy('key');

        if (! $definitions->has('age')) {
            $fieldDefinitions->handle(new FieldDefinitionData(
                'الفئة العمرية', FieldType::Select, key: 'age', isFilterable: true, filterOrder: 10, sortOrder: 10, options: self::AGES, sectionId: $this->sectionId('toys'),
            ));
        }

        foreach (['brand' => self::BRANDS, 'size' => self::SIZES] as $key => $labels) {
            $definition = $definitions->get($key);

            if (! $definition instanceof ProductFieldDefinition) {
                continue;
            }

            $existing = $definition->options->pluck('label')->all();

            foreach ($labels as $index => $label) {
                if (! in_array($label, $existing, true)) {
                    $fieldOptions->handle($definition, $label, $index + 1);
                }
            }
        }

        foreach ($fields->get() as $field) {
            foreach ($field->options as $option) {
                $this->options[$field->key][$option->label] = $option->id;
            }
        }
    }

    private function books(CreateCategory $categories, CreateProduct $products, AddProductRelation $relations, CreateBundle $bundles, int $copy, int $carton): void
    {
        $books = $categories->handle(new CategoryData('كتب خارجية', null, 1, sectionId: $this->sectionId('books')));
        $primary = $categories->handle(new CategoryData('المرحلة الابتدائية', $books->id, 1));
        $prep = $categories->handle(new CategoryData('المرحلة الإعدادية', $books->id, 2));
        $categories->handle(new CategoryData('المرحلة الثانوية', $books->id, 3));
        $grades = [
            'أولى إعدادي' => $categories->handle(new CategoryData('أولى إعدادي', $prep->id, 1))->id,
            'ثانية إعدادي' => $categories->handle(new CategoryData('ثانية إعدادي', $prep->id, 2))->id,
        ];
        $fourth = $categories->handle(new CategoryData('رابعة ابتدائي', $primary->id, 1))->id;

        $number = 0;
        $main = null;
        $set = [];

        foreach ($grades as $grade => $categoryId) {
            foreach (self::SUBJECTS as $subject) {
                foreach (self::PUBLISHERS as $publisher) {
                    $number++;
                    $product = $products->handle($this->book($number, "{$publisher} {$subject} {$grade}", $categoryId, $grade, $subject, $publisher, $copy, $carton));
                    $main ??= $product;

                    if ($grade === 'أولى إعدادي' && $publisher === 'الأضواء' && $subject !== 'إنجليزي') {
                        $set[] = $product; // sold by the copy (the English book goes by the carton)
                    }
                }
            }
        }

        foreach (['عربي', 'رياضيات'] as $subject) {
            $number++;
            $products->handle($this->book($number, "الأضواء {$subject} رابعة ابتدائي", $fourth, 'رابعة ابتدائي', $subject, 'الأضواء', $copy, $carton));
        }

        $number++;
        $products->handle($this->book($number, 'المعلم علوم رابعة ابتدائي', $fourth, 'رابعة ابتدائي', 'علوم', 'المعلم', $copy, $carton, ProductStatus::ComingSoon));
        $number++;
        $revision = $products->handle($this->book($number, "المراجعة النهائية {$main->name}", $grades['أولى إعدادي'], 'أولى إعدادي', 'عربي', 'الأضواء', $copy, $carton));
        $relations->handle($revision, $main->id, RelationType::RevisionOf);
        $bundles->handle($this->gradeSet($set, $grades['أولى إعدادي'], $copy));
    }

    /**
     * D93: «باقة الأضواء أولى إعدادي», one copy of each given book at the books' sum less 10%, sold by the copy.
     *
     * @param  list<Product>  $books
     */
    private function gradeSet(array $books, int $categoryId, int $copyId): BundleData
    {
        $components = array_map(fn (Product $book): BundleComponentData => new BundleComponentData(
            $book->skus()->sole()->defaultUnit->id ?? throw new LogicException("{$book->name} has no default unit."),
            1,
        ), $books);

        return new BundleData(
            name: 'باقة الأضواء أولى إعدادي',
            categoryId: $categoryId,
            code: 'SET-DEMO-1',
            unitId: $copyId,
            components: $components === [] ? throw new LogicException('The grade set has no books.') : $components,
            pricing: new BundlePricingData(BundlePriceMode::PercentOffSum, '10.00'),
            description: 'كتب الأضواء لأولى إعدادي، الترم الأول، في باقة واحدة بخصم 10% على مجموع أسعارها.',
            customFields: [
                'grade' => $this->option('grade', 'أولى إعدادي'),
                'publisher' => $this->option('publisher', 'الأضواء'),
                'term' => $this->option('term', 'الترم الأول'),
            ],
        );
    }

    /** Pens sell by the carton of 12 (one carton MOQ), notebooks by the piece (10 MOQ); every item has a retail price. */
    private function supplies(CreateCategory $categories, CreateProduct $products, int $piece, int $carton): void
    {
        $root = $categories->handle(new CategoryData('أدوات دراسية', null, 2, sectionId: $this->sectionId('stationery')));
        $pens = $categories->handle(new CategoryData('أقلام', $root->id, 1))->id;
        $notebooks = $categories->handle(new CategoryData('كراسات', $root->id, 2))->id;

        foreach (self::BRANDS as $index => $brand) {
            $products->handle($this->item(
                code: sprintf('ST-%03d', $index + 1),
                name: "قلم جاف {$brand} أزرق",
                categoryId: $pens,
                fields: ['brand' => $this->option('brand', $brand), 'color' => $this->option('color', 'أزرق')],
                retail: 5 + $index * 2,
                sellingUnitId: $carton,
                baseUnitId: $piece,
                factor: 12,
                moq: 1,
                description: "قلم جاف {$brand} أزرق، كرتونة 12 قلم، خط ناعم ومناسب للطلاب.",
            ));
        }

        foreach (self::SIZES as $index => $size) {
            $products->handle($this->item(
                code: sprintf('ST-%03d', $index + 4),
                name: "كراسة بريما {$size} 100 ورقة",
                categoryId: $notebooks,
                fields: ['brand' => $this->option('brand', 'بريما'), 'size' => $this->option('size', $size), 'color' => $this->option('color', 'ألوان متعددة')],
                retail: 25 + $index * 10,
                sellingUnitId: $piece,
                baseUnitId: $piece,
                factor: 1,
                moq: 10,
                description: "كراسة بريما {$size} سلك، 100 ورقة مسطرة، أغلفة بألوان متعددة.",
            ));
        }

        $products->handle($this->item(
            code: 'ST-006',
            name: 'كشكول روتو A4 4 مواضيع',
            categoryId: $notebooks,
            fields: ['brand' => $this->option('brand', 'روتو'), 'size' => $this->option('size', 'A4'), 'color' => $this->option('color', 'أسود')],
            retail: 60,
            sellingUnitId: $piece,
            baseUnitId: $piece,
            factor: 1,
            moq: 6,
            description: 'كشكول روتو A4 سلك، 4 مواضيع بفواصل ملونة، غلاف أسود مقوى.',
            carton: [$carton, 24], // D91: the piece and the carton of 24 draw on the same stock
        ));

        $products->handle($this->highlighter($pens, $piece));
    }

    /**
     * BR-P14 (V2 Task 14): a highlighter in three colours, each colour its own SKU (ST-007-1..3) with its own stock, the
     * pink one a little dearer; the codes follow VariantGenerator (Arabic values slugged by position).
     */
    private function highlighter(int $categoryId, int $piece): ProductData
    {
        $colours = new ProductOptionData(null, 'اللون', [new ProductOptionValueData(null, 'أصفر'), new ProductOptionValueData(null, 'أخضر'), new ProductOptionValueData(null, 'وردي')]);
        $skus = [];

        foreach (app(VariantGenerator::class)->generate('ST-007', [$colours]) as $index => $draft) {
            $skus[] = new SkuData(
                code: $draft['code'],
                baseUnitId: $piece,
                units: [new SkuUnitData(null, $piece, 1, true, new SkuPricingData(basePrice: $index === 2 ? '9.00' : '8.00', moq: 12, coverPrice: '12.00'))],
                optionValues: $draft['option_values'],
                openingStock: 600 - $index * 200,
                path: "skus.{$index}",
            );
        }

        return new ProductData(
            name: 'قلم هايلايتر فابر كاستل',
            categoryId: $categoryId,
            skus: $skus !== [] ? $skus : throw new LogicException('Three colours make three variants.'),
            options: [$colours],
            description: 'قلم هايلايتر بثلاثة ألوان، رأس مشطوف للتظليل والكتابة.',
            customFields: ['brand' => $this->option('brand', 'فابر كاستل')],
            status: ProductStatus::Active,
        );
    }

    /** Toys sell by the piece (3 MOQ); the first two carry a retail price, the last two none (D68). */
    private function toys(CreateCategory $categories, CreateProduct $products, int $piece): void
    {
        $root = $categories->handle(new CategoryData('ألعاب', null, 3, sectionId: $this->sectionId('toys')))->id;

        $items = [
            ['مكعبات تركيب 100 قطعة', '3+', 120],
            ['بازل خشبي حروف عربية', '3+', 80],
            ['لعبة شطرنج مغناطيسية', '9+', null],
            ['صلصال 12 لون', '6+', null],
        ];

        foreach ($items as $index => [$name, $age, $retail]) {
            $products->handle($this->item(
                code: sprintf('TY-%03d', $index + 1),
                name: $name,
                categoryId: $root,
                fields: ['age' => $this->option('age', $age)],
                retail: $retail,
                sellingUnitId: $piece,
                baseUnitId: $piece,
                factor: 1,
                moq: 3,
                description: "{$name} للأطفال من سن {$age}، تغليف هدايا جاهز للبيع.",
            ));
        }
    }

    private function storeSettings(): TenantSettingsData
    {
        $hours = array_map(fn (int $day): array => ['day' => $day, 'from' => '09:00', 'to' => '21:00'], [0, 1, 2, 3, 4, 6]);

        return new TenantSettingsData(
            name: null,
            primaryColor: '#1F3C88',
            secondaryColor: '#EF6A4C',
            phone: '+201001234567',
            whatsapp: '+201001234567',
            address: '12 شارع الفجالة، القاهرة',
            description: 'كتب خارجية وأدوات دراسية وألعاب بالجملة لكل المراحل.',
            workingHours: $hours,
            movEnabled: true,
            movAmount: '500.00',
            requireLoginForPrices: null,
            requireCustomerApproval: null,
            stalePendingHours: null,
            invitationMessage: null,
            sent: ['primary_color', 'secondary_color', 'phone', 'whatsapp', 'address', 'description', 'working_hours', 'mov_enabled', 'mov_amount', 'order_notify_statuses'],
            // BR-N03: the demo customers have invented phones, so the demo store tells nobody about order moves
            // (tenant:demo also runs on production); the merchant turns the messages on in settings.
            orderNotifyStatuses: [],
        );
    }

    /**
     * Book n: cover 100–190, wholesale 20% off; every 4th sells in cartons of 20 (MOQ 2), every 3rd has a 10+ tier.
     */
    private function book(int $n, string $name, int $categoryId, string $grade, string $subject, string $publisher, int $copyId, int $cartonId, ProductStatus $status = ProductStatus::Active): ProductData
    {
        $byCarton = $n % 4 === 0;
        $factor = $byCarton ? 20 : 1;
        $cover = (100 + ($n % 10) * 10) * $factor;
        $base = $cover * 0.8;
        $tiers = $n % 3 === 0 ? [new TierData(10, number_format($base * 0.95, 2, '.', ''))] : null;

        return new ProductData(
            name: $name,
            categoryId: $categoryId,
            skus: [new SkuData(
                code: sprintf('BK-%03d', $n),
                baseUnitId: $copyId,
                units: [new SkuUnitData(
                    id: null,
                    unitId: $byCarton ? $cartonId : $copyId,
                    conversionFactor: $factor,
                    isDefault: true,
                    pricing: new SkuPricingData(
                        basePrice: number_format($base, 2, '.', ''),
                        moq: $byCarton ? 2 : 1,
                        tiers: $tiers,
                        coverPrice: number_format($cover, 2, '.', ''),
                    ),
                )],
                openingStock: $status === ProductStatus::ComingSoon ? 0 : 1000,
            )],
            description: $this->description($name, $grade, $subject, $publisher),
            customFields: [
                'grade' => $this->option('grade', $grade),
                'subject' => $this->option('subject', $subject),
                'publisher' => $this->option('publisher', $publisher),
                'term' => $this->option('term', 'الترم الأول'),
            ],
            status: $status,
            // BR-O14 (D88): the Coming Soon book takes pre-orders until it arrives in three weeks.
            preorder: $status === ProductStatus::ComingSoon ? new PreorderData(true, now()->addDays(21)->startOfDay()->toImmutable(), 500) : null,
        );
    }

    /**
     * A non-book item: the retail price per piece (null = none) is the reference price; wholesale is 25% below
     * it, or a flat 45.00 per piece when there is none. `$carton` (`[unit id, pieces]`) also sells it by the carton (D91).
     *
     * @param  array<string, int>  $fields
     * @param  array{0: int, 1: int}|null  $carton
     */
    private function item(string $code, string $name, int $categoryId, array $fields, ?int $retail, int $sellingUnitId, int $baseUnitId, int $factor, int $moq, string $description, ?array $carton = null): ProductData
    {
        $retailPerUnit = $retail === null ? null : $retail * $factor;
        $base = $retailPerUnit === null ? 45 * $factor : $retailPerUnit * 0.75;

        return new ProductData(
            name: $name,
            categoryId: $categoryId,
            skus: [new SkuData(
                code: $code,
                baseUnitId: $baseUnitId,
                units: [
                    new SkuUnitData(
                        id: null,
                        unitId: $sellingUnitId,
                        conversionFactor: $factor,
                        isDefault: true,
                        pricing: new SkuPricingData(
                            basePrice: number_format($base, 2, '.', ''),
                            moq: $moq,
                            coverPrice: $retailPerUnit === null ? null : number_format($retailPerUnit, 2, '.', ''),
                        ),
                    ),
                    ...($carton === null ? [] : [$this->carton($carton, $retail, $base)]),
                ],
                openingStock: 1000,
            )],
            description: $description,
            customFields: $fields,
            status: ProductStatus::Active,
        );
    }

    /**
     * D91: the carton of an item sold by the piece, cheaper per piece than the piece price (10% off it), one carton MOQ.
     *
     * @param  array{0: int, 1: int}  $carton
     */
    private function carton(array $carton, ?int $retail, float $piecePrice): SkuUnitData
    {
        [$unitId, $pieces] = $carton;

        return new SkuUnitData(
            id: null,
            unitId: $unitId,
            conversionFactor: $pieces,
            isDefault: false,
            pricing: new SkuPricingData(
                basePrice: number_format($piecePrice * $pieces * 0.9, 2, '.', ''),
                moq: 1,
                coverPrice: $retail === null ? null : number_format($retail * $pieces, 2, '.', ''),
            ),
        );
    }

    /** A description as the publisher's back cover reads: what the book covers and how it teaches it. */
    private function description(string $name, string $grade, string $subject, string $publisher): string
    {
        $covers = [
            'عربي' => 'النحو والقراءة والنصوص والقصة والتعبير',
            'إنجليزي' => 'القواعد والمفردات وقطع القراءة والكتابة',
            'رياضيات' => 'الجبر والهندسة والإحصاء',
            'علوم' => 'كل وحدات المنهج بالرسوم التوضيحية والأنشطة العملية',
            'دراسات' => 'الجغرافيا والتاريخ بالخرائط والجداول الزمنية',
        ][$subject];

        if (str_starts_with($name, 'المراجعة النهائية')) {
            return "مراجعة ليلة الامتحان في مادة {$subject} لطلاب {$grade}، الترم الأول، طبعة 2026/2027: ملخص المنهج كله في صفحات قليلة، أهم الأسئلة المتوقعة، و10 نماذج امتحانات بالإجابات النموذجية.";
        }

        $method = [
            'الأضواء' => 'شرح مبسّط لكل درس، وتدريبات متدرجة من السهل للصعب، ونماذج امتحانات بالإجابات',
            'المعلم' => 'ملخصات مركّزة، وأسئلة بنظام التقييمات الأسبوعية، واختبارات شهرية بالإجابات',
            'سلاح التلميذ' => 'بنك أسئلة شامل بأفكار الامتحانات، وإجابات نموذجية مفصّلة لكل سؤال',
        ][$publisher];

        return "كتاب {$publisher} في مادة {$subject} لطلاب {$grade}، الترم الأول، طبعة 2026/2027 مطابقة للمنهج الجديد. يغطي {$covers}، مع {$method}.";
    }

    private function option(string $key, string $label): int
    {
        return $this->options[$key][$label] ?? throw new RuntimeException("The templates have no {$key} option {$label}.");
    }

    /** D109: the store's section with this key, created with the store from its vertical. */
    private function sectionId(string $key): int
    {
        return Section::query()->where('key', $key)->firstOrFail()->id;
    }
}
