<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

/**
 * One line that fails checkout (BR-PR06), as an `items[]` entry of `422 checkout_failed`, naming its SKU and SKU unit
 * (D91): `product_inactive` (or the unit is no longer sold), `below_moq` with the unit's `moq`, or `insufficient_stock`
 * with `requested` and `available` in the line's unit (what is left after the order's earlier lines of the same SKU). A pre-order
 * (BR-O14, D88) adds `preorder_unavailable` (the product no longer takes pre-orders) and `preorder_limit` with
 * `requested` and `remaining` under the merchant's cap, in selling units.
 */
final readonly class CheckoutProblem
{
    /**
     * @param  array<string, int>  $extra
     */
    public function __construct(
        public int $skuId,
        public int $skuUnitId,
        public string $reason,
        public array $extra = [],
    ) {}

    public static function productInactive(int $skuId, int $skuUnitId): self
    {
        return new self($skuId, $skuUnitId, 'product_inactive');
    }

    public static function belowMoq(int $skuId, int $skuUnitId, int $moq): self
    {
        return new self($skuId, $skuUnitId, 'below_moq', ['moq' => $moq]);
    }

    public static function insufficientStock(int $skuId, int $skuUnitId, int $requested, int $available): self
    {
        return new self($skuId, $skuUnitId, 'insufficient_stock', ['requested' => $requested, 'available' => $available]);
    }

    public static function preorderUnavailable(int $skuId, int $skuUnitId): self
    {
        return new self($skuId, $skuUnitId, 'preorder_unavailable');
    }

    public static function preorderLimit(int $skuId, int $skuUnitId, int $requested, int $remaining): self
    {
        return new self($skuId, $skuUnitId, 'preorder_limit', ['requested' => $requested, 'remaining' => $remaining]);
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return ['sku_id' => $this->skuId, 'sku_unit_id' => $this->skuUnitId, 'reason' => $this->reason, ...$this->extra];
    }
}
