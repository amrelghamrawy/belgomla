<?php

declare(strict_types=1);

namespace App\Modules\Platform\Data;

use App\Modules\Platform\Enums\VerticalStatus;

/** A store vertical as other modules see it (D108), read by VerticalTemplateQuery. */
final readonly class VerticalData
{
    public function __construct(
        public int $id,
        public string $key,
        public string $name,
        public VerticalStatus $status,
    ) {}
}
