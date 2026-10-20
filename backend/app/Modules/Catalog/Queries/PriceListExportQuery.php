<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use Illuminate\Support\LazyCollection;

/**
 * BR-P13 price list as a file (V2 Task 06): PriceListQuery's selection and order, without its row cap, read lazily
 * in chunks of `CHUNK`.
 */
final readonly class PriceListExportQuery
{
    public const int CHUNK = 500;

    public function __construct(private PriceListQuery $priceList) {}

    /**
     * @return LazyCollection<int, Product>
     */
    public function get(ProductFilterData $filter): LazyCollection
    {
        return $this->priceList->builder($filter)->lazy(self::CHUNK);
    }
}
