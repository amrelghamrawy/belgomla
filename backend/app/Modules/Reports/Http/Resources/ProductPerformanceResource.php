<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One SKU of the product performance report (V2 Task 16, 08 §11): quantities (selling, base, of which inside
 * bundles), net revenue and returns, the return rate, customers and the last sale.
 *
 * @phpstan-import-type ProductRow from \App\Modules\Reports\Queries\ProductPerformanceQuery
 *
 * @property-read ProductRow $resource
 */
final class ProductPerformanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'sku_id' => $row['sku_id'],
            'product_id' => $row['product_id'],
            'product_name' => $row['product_name'],
            'sku_code' => $row['sku_code'],
            'sku_name' => $row['sku_name'],
            'is_bundle' => $row['is_bundle'],
            'quantity' => $row['quantity'],
            'quantity_base' => $row['quantity_base'],
            'bundle_quantity_base' => $row['bundle_quantity_base'],
            'revenue' => $row['revenue'],
            'returns' => $row['returns'],
            'returned_base' => $row['returned_base'],
            'return_rate' => $row['return_rate'],
            'customers' => $row['customers'],
            'last_sold_at' => $row['last_sold_at'],
        ];
    }
}
