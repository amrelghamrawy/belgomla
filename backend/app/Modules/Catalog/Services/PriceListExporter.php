<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Exceptions\PricesHidden;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\PriceListExportQuery;
use App\Modules\Catalog\Queries\PriceVisibilityQuery;
use App\Modules\Pricing\Contracts\CustomerPricing;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Services\PriceResolver;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * BR-P13 price list as an Excel file (V2 Task 06, D83): the storefront price list's rows (name, code, unit, MOQ,
 * reference and wholesale price, availability) for any facet selection, uncapped. Prices follow D1: when the store
 * hides them from guests, a guest gets `403 prices_hidden` rather than a file without prices. "PDF" stays the
 * browser's print of the price list page. The wholesale price is the shopper's own (BR-PR14): their negotiated or group
 * price when it is lower.
 */
final readonly class PriceListExporter
{
    public const array COLUMNS = [
        'name' => 'المنتج',
        'section' => 'القسم',
        'code' => 'الكود',
        'unit' => 'الوحدة',
        'moq' => 'الحد الأدنى للطلب',
        'cover_price' => 'السعر المرجعي (الغلاف أو القطاعي)',
        'base_price' => 'سعر الجملة',
        'availability' => 'التوفر',
    ];

    public function __construct(
        private Spreadsheet $spreadsheet,
        private PriceListExportQuery $query,
        private PriceVisibilityQuery $prices,
        private CustomerPricing $pricing,
        private PriceResolver $resolver,
    ) {}

    /**
     * Checks the visibility now, so a refusal is a JSON error, and returns what streams the file.
     *
     * @return Closure(): void
     *
     * @throws PricesHidden
     */
    public function writer(ProductFilterData $filter, ?Authenticatable $customer): Closure
    {
        if ($this->prices->hiddenFor($customer)) {
            throw PricesHidden::forGuest();
        }

        $pricing = $this->pricing->for($customer);

        return fn () => $this->write('php://output', $filter, $pricing);
    }

    public function write(string $path, ProductFilterData $filter, ?PricingContext $pricing = null): void
    {
        $this->spreadsheet->write($path, SpreadsheetFormat::Xlsx, self::COLUMNS, $this->query->get($filter)->map(fn (Product $product): array => $this->row($product, $pricing)), 'قائمة الأسعار');
    }

    /**
     * @return array<string, string|int|float|null>
     */
    private function row(Product $product, ?PricingContext $pricing): array
    {
        $sku = $product->sku;

        return [
            'name' => $product->name,
            'section' => $product->category->kind->label(),
            'code' => $sku?->code,
            'unit' => $sku instanceof Sku ? $sku->sellingUnit->name : null,
            'moq' => $sku?->moq,
            'cover_price' => $sku?->cover_price === null ? null : (float) $sku->cover_price,
            'base_price' => $sku instanceof Sku ? (float) $this->resolver->listed($sku, $pricing)->basePrice : null,
            'availability' => $product->status === ProductStatus::ComingSoon ? 'قريبًا' : 'متاح',
        ];
    }
}
