<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;

/** Adds an option to a `select` field; without a sort order it goes last (BR-P08). */
final readonly class CreateFieldOption
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(ProductFieldDefinition $definition, string $label, ?int $sortOrder = null): ProductFieldOption
    {
        if ($definition->type !== FieldType::Select) {
            throw InvalidCatalogInput::notASelectField();
        }

        if ($definition->options()->where('label', $label)->exists()) {
            throw CatalogConflict::duplicateOptionLabel();
        }

        $option = $definition->options()->create([
            'label' => $label,
            'sort_order' => $sortOrder ?? $this->nextSortOrder($definition),
        ]);

        $this->audit->log('field_option.created', $option, null, ['definition_id' => $definition->id, 'label' => $label, 'sort_order' => $option->sort_order]);

        return $option->refresh();
    }

    private function nextSortOrder(ProductFieldDefinition $definition): int
    {
        $max = ProductFieldOption::query()->where('definition_id', $definition->id)->max('sort_order');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }
}
