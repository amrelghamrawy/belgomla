<?php

declare(strict_types=1);

namespace App\Modules\Platform\Data;

/** An active default section of a vertical (D109 a), as copied into a tenant. `icon` is a CategoryIcon value. */
final readonly class SectionTemplateData
{
    public function __construct(
        public int $id,
        public string $key,
        public string $name,
        public string $referencePriceLabel,
        public ?string $icon,
        public int $sortOrder,
    ) {}
}
