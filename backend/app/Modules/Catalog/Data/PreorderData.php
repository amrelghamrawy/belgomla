<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use Carbon\CarbonImmutable;

/**
 * A product's pre-order settings (BR-P04, BR-O14, D88): whether customers may pre-order it while it is Coming Soon, the
 * day it is expected (shown on the storefront) and the most selling units every open pre-order may add up to (null = no
 * cap). `fromRequest` is null when the request leaves `preorder` out: a create starts with pre-orders off, an update
 * keeps what the product has (like the tiers, D34).
 */
final readonly class PreorderData
{
    public function __construct(
        public bool $enabled,
        public ?CarbonImmutable $expectedAt = null,
        public ?int $limit = null,
    ) {}

    public static function fromRequest(StoreProductRequest|UpdateProductRequest $request): ?self
    {
        if (! $request->hasPreorder()) {
            return null;
        }

        return new self($request->preorderEnabled(), $request->preorderExpectedAt(), $request->preorderLimit());
    }

    /**
     * Column => value for the products table.
     *
     * @return array{preorder_enabled: bool, expected_at: CarbonImmutable|null, preorder_limit: int|null}
     */
    public function attributes(): array
    {
        return [
            'preorder_enabled' => $this->enabled,
            'expected_at' => $this->expectedAt,
            'preorder_limit' => $this->limit,
        ];
    }
}
