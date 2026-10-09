<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

/**
 * One SKU that fails checkout (BR-PR06), as an `items[]` entry of `422 checkout_failed`: `product_inactive`,
 * `below_moq` with `moq`, or `insufficient_stock` with `requested` and `available` in selling units.
 */
final readonly class CheckoutProblem
{
    /**
     * @param  array<string, int>  $extra
     */
    public function __construct(
        public int $skuId,
        public string $reason,
        public array $extra = [],
    ) {}

    public static function productInactive(int $skuId): self
    {
        return new self($skuId, 'product_inactive');
    }

    public static function belowMoq(int $skuId, int $moq): self
    {
        return new self($skuId, 'below_moq', ['moq' => $moq]);
    }

    public static function insufficientStock(int $skuId, int $requested, int $available): self
    {
        return new self($skuId, 'insufficient_stock', ['requested' => $requested, 'available' => $available]);
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return ['sku_id' => $this->skuId, 'reason' => $this->reason, ...$this->extra];
    }
}
