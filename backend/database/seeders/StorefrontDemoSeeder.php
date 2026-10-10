<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Actions\AddProductRelation;
use App\Modules\Catalog\Actions\CreateCategory;
use App\Modules\Catalog\Actions\CreateProduct;
use App\Modules\Catalog\Actions\CreateUnit;
use App\Modules\Catalog\Data\CategoryData;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\RelationType;
use App\Modules\Catalog\Queries\CategoryTreeQuery;
use App\Modules\Catalog\Queries\FieldDefinitionsQuery;
use App\Modules\Catalog\Queries\UnitsQuery;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Tenancy\Actions\UpdateTenantSettings;
use App\Modules\Tenancy\Data\TenantSettingsData;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Local development and Playwright catalog for the `alnoor` school-books tenant (tasks/9): store contacts, a
 * minimum order value of 500, a category tree, 34 books with grade/subject/publisher facets, cover and wholesale
 * prices, a few tiers and carton MOQs, stock on every book, one coming-soon book and one final-revision edition
 * linked to its main book. Everything goes through the module actions, like a merchant would. Runs once.
 */
final class StorefrontDemoSeeder extends Seeder
{
    private const array SUBJECTS = ['عربي', 'إنجليزي', 'رياضيات', 'علوم', 'دراسات'];

    private const array PUBLISHERS = ['الأضواء', 'المعلم', 'سلاح التلميذ'];

    /** @var array<string, array<string, int>> option id by field key and label */
    private array $options = [];

    public function run(
        TenantContext $context,
        CategoryTreeQuery $tree,
        UnitsQuery $existingUnits,
        FieldDefinitionsQuery $fields,
        UpdateTenantSettings $settings,
        CreateUnit $units,
        CreateCategory $categories,
        CreateProduct $products,
        AddProductRelation $relations,
    ): void {
        $tenant = Tenant::query()->where('subdomain', 'alnoor')->firstOrFail();

        $context->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $tree, $existingUnits, $fields, $settings, $units, $categories, $products, $relations): void {
            // The demo tree's first root marks a seeded catalog; a dev database may hold other test data.
            if ($tree->forDashboard()->contains('name', 'المرحلة الابتدائية')) {
                return;
            }

            foreach ($fields->get() as $field) {
                foreach ($field->options as $option) {
                    $this->options[$field->key][$option->label] = $option->id;
                }
            }

            $settings->handle($tenant, TenantSetting::query()->firstOrFail(), $this->storeSettings());

            $known = [];

            foreach ($existingUnits->get() as $unit) {
                $known[$unit->name] = $unit->id;
            }

            $copy = $known['نسخة'] ?? $units->handle('نسخة')->id;
            $carton = $known['كرتونة'] ?? $units->handle('كرتونة')->id;

            $primary = $categories->handle(new CategoryData('المرحلة الابتدائية', null, 1));
            $prep = $categories->handle(new CategoryData('المرحلة الإعدادية', null, 2));
            $categories->handle(new CategoryData('المرحلة الثانوية', null, 3));
            $grades = [
                'أولى إعدادي' => $categories->handle(new CategoryData('أولى إعدادي', $prep->id, 1))->id,
                'ثانية إعدادي' => $categories->handle(new CategoryData('ثانية إعدادي', $prep->id, 2))->id,
            ];
            $fourth = $categories->handle(new CategoryData('رابعة ابتدائي', $primary->id, 1))->id;

            $number = 0;
            $main = null;

            foreach ($grades as $grade => $categoryId) {
                foreach (self::SUBJECTS as $subject) {
                    foreach (self::PUBLISHERS as $publisher) {
                        $number++;
                        $product = $products->handle($this->book($number, "{$publisher} {$subject} {$grade}", $categoryId, $grade, $subject, $publisher, $copy, $carton));
                        $main ??= $product;
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
        }));
    }

    private function storeSettings(): TenantSettingsData
    {
        $hours = array_map(fn (int $day): array => ['day' => $day, 'from' => '09:00', 'to' => '21:00'], [0, 1, 2, 3, 4, 6]);

        return new TenantSettingsData(
            name: null,
            primaryColor: '#0A5C36',
            secondaryColor: '#F5B400',
            phone: '+201001234567',
            whatsapp: '+201001234567',
            address: '12 شارع الفجالة، القاهرة',
            description: 'كتب خارجية ومستلزمات مدرسية بالجملة لكل المراحل.',
            workingHours: $hours,
            movEnabled: true,
            movAmount: '500.00',
            requireLoginForPrices: null,
            requireCustomerApproval: null,
            stalePendingHours: null,
            sent: ['primary_color', 'secondary_color', 'phone', 'whatsapp', 'address', 'description', 'working_hours', 'mov_enabled', 'mov_amount'],
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
            sku: new SkuData(
                code: sprintf('BK-%03d', $n),
                sellingUnitId: $byCarton ? $cartonId : $copyId,
                baseUnitId: $copyId,
                conversionFactor: $factor,
                pricing: new SkuPricingData(
                    basePrice: number_format($base, 2, '.', ''),
                    moq: $byCarton ? 2 : 1,
                    tiers: $tiers,
                    coverPrice: number_format($cover, 2, '.', ''),
                ),
            ),
            description: $this->description($name, $grade, $subject, $publisher),
            customFields: [
                'grade' => $this->option('grade', $grade),
                'subject' => $this->option('subject', $subject),
                'publisher' => $this->option('publisher', $publisher),
                'term' => $this->option('term', 'الترم الأول'),
            ],
            status: $status,
            openingStock: $status === ProductStatus::ComingSoon ? 0 : 1000,
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
        return $this->options[$key][$label] ?? throw new RuntimeException("The school-books template has no {$key} option {$label}.");
    }
}
