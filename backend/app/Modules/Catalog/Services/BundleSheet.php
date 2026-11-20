<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Data\CatalogLookups;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\BundleExportQuery;
use App\Modules\Pricing\Services\BundlePricer;

/**
 * The `bundles_v1` sheet of the catalog export (BR-P15, V2 Task 15, D93): bundles are exported, never imported. One row
 * per component, in the bundle's order: the bundle (code, name, status, category path, selling unit, MOQ, pricing mode
 * and value, its price = its unit's base price) then the component (SKU code, unit, quantity). Arabic labels over a key
 * row, like the products sheet (CatalogRowMapper); the products sheet leaves bundles out, and an import refuses a row
 * with a bundle's code (`NOT_IMPORTED`) and never reads this sheet.
 *
 * @phpstan-type CellValue string|int|float|bool|null
 */
final readonly class BundleSheet
{
    public const string SHEET_NAME = 'bundles_v1';

    public const string NOT_IMPORTED = 'الباقات لا تُستورد من الملف';

    public const array LABELS = [
        'bundle_code' => 'كود الباقة',
        'bundle_name' => 'اسم الباقة',
        'status' => 'الحالة',
        'category_path' => 'التصنيف',
        'unit' => 'وحدة البيع',
        'moq' => 'الحد الأدنى للطلب',
        'pricing_mode' => 'طريقة التسعير',
        'pricing_value' => 'قيمة التسعير',
        'bundle_price' => 'سعر الباقة',
        'component_code' => 'كود الصنف',
        'component_unit' => 'وحدة الصنف',
        'component_quantity' => 'الكمية في الباقة',
    ];

    public function __construct(
        private BundleExportQuery $query,
        private BundlePricer $pricer,
    ) {}

    /**
     * The sheet for Spreadsheet::write's `moreSheets`, its rows read lazily.
     *
     * @return array{name: string, headers: array<string, string>, rows: iterable<array<string, CellValue>>}
     */
    public function sheet(ProductFilterData $filter, CatalogLookups $lookups): array
    {
        return [
            'name' => self::SHEET_NAME,
            'headers' => self::LABELS,
            'rows' => $this->query->get($filter)->flatMap(fn (Product $bundle): array => $this->rows($bundle, $lookups)),
        ];
    }

    /**
     * The rows of one bundle (its SKU's components loaded, BundleExportQuery): one per component.
     *
     * @return list<array<string, CellValue>>
     */
    public function rows(Product $bundle, CatalogLookups $lookups): array
    {
        $sku = $bundle->skus->first();
        $head = $this->head($bundle, $sku, $lookups);
        $rows = [];

        foreach ($sku->bundleItems ?? [] as $item) {
            $rows[] = [...$head, ...$this->component($item)];
        }

        return $rows === [] ? [$head] : $rows;
    }

    /**
     * @return array<string, CellValue>
     */
    private function head(Product $bundle, ?Sku $sku, CatalogLookups $lookups): array
    {
        $pricing = $sku instanceof Sku ? $this->pricer->pricingOf($sku) : null;
        $unit = $sku?->defaultUnit;

        return [
            'bundle_code' => $sku?->code,
            'bundle_name' => $bundle->name,
            'status' => $bundle->status->label(),
            'category_path' => $lookups->paths[$bundle->category_id] ?? '',
            'unit' => $unit?->unit->name,
            'moq' => $unit?->moq,
            'pricing_mode' => $pricing?->mode->label(),
            'pricing_value' => $this->number($pricing?->value),
            'bundle_price' => $this->number($unit?->base_price),
        ];
    }

    /**
     * @return array<string, CellValue>
     */
    private function component(BundleItem $item): array
    {
        return [
            'component_code' => $item->componentUnit->sku->code,
            'component_unit' => $item->componentUnit->unit->name,
            'component_quantity' => $item->quantity,
        ];
    }

    /** Money as a number, so the sheet can compute with it (like the products sheet). */
    private function number(?string $amount): int|float|null
    {
        if ($amount === null) {
            return null;
        }

        $value = (float) $amount;

        return floor($value) === $value ? (int) $value : $value;
    }
}
