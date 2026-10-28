<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

/**
 * One value of a variant option (BR-P14): `id` null = a new value (or the option's value with the same text), `imageId`
 * one of the product's images shown as the swatch. Built by ProductOptionData.
 */
final readonly class ProductOptionValueData
{
    public function __construct(
        public ?int $id,
        public string $value,
        public ?int $imageId = null,
    ) {}
}
