<?php

declare(strict_types=1);

namespace App\Modules\Platform\Data;

use App\Modules\Catalog\Enums\FieldType;

/**
 * An active field template of a vertical with its options, as copied into a tenant (BR-P09, D110).
 * `sectionKey` is the key of its vertical section, null = every section.
 */
final readonly class FieldTemplateData
{
    /**
     * @param  list<string>  $options  labels in sort order
     */
    public function __construct(
        public int $id,
        public string $key,
        public string $label,
        public FieldType $type,
        public ?string $sectionKey,
        public bool $isRequired,
        public bool $isFilterable,
        public int $filterOrder,
        public int $sortOrder,
        public array $options,
    ) {}
}
