<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Queries\LowStockQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/reports/low-stock (D5 c): Inventory's low-stock SKUs (D36: Available ≤ MOQ × conversion
 * factor, products not inactive) with their product and stock, by SKU code.
 */
final readonly class LowStockReportQuery
{
    public function __construct(private LowStockQuery $lowStock) {}

    /**
     * @return LengthAwarePaginator<int, Sku>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->lowStock->builder()->with(['product', 'inventory'])->orderBy('skus.code')->orderBy('skus.id')->paginate($perPage);
    }
}
