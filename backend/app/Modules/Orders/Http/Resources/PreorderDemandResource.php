<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product's pre-order demand (BR-O14, D88): the open pre-ordered `quantity` (selling units), how many `customers` and
 * `preorders` hold it, the product's pre-order settings and what is `remaining` under its cap (null = no cap).
 *
 * @property-read Product $resource
 */
final class PreorderDemandResource extends JsonResource
{
    /**
     * @param  array{quantity: int, customers: int, preorders: int}  $demand
     */
    public function __construct(Product $resource, private readonly array $demand)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        return [
            'product_id' => $product->id,
            'status' => $product->status->value,
            'preorder_enabled' => $product->preorder_enabled,
            'expected_at' => $product->expected_at?->toDateString(),
            'limit' => $product->preorder_limit,
            'remaining' => $product->preorder_limit === null ? null : max(0, $product->preorder_limit - $this->demand['quantity']),
            ...$this->demand,
        ];
    }
}
