<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Tenancy\Enums\Vertical;
use RuntimeException;

/**
 * Reads the predefined custom-field templates in database/verticals/<vertical>.php (BR-P09).
 *
 * @phpstan-type TemplateField array{key: string, label: string, type: FieldType, kind: CategoryKind|null, is_filterable: bool, filter_order: int, sort_order: int, options: list<string>}
 */
final readonly class VerticalTemplates
{
    public function __construct(private ?string $directory = null) {}

    /**
     * @return list<TemplateField>
     */
    public function fieldsFor(Vertical $vertical): array
    {
        $file = ($this->directory ?? database_path('verticals')).DIRECTORY_SEPARATOR.$vertical->value.'.php';

        if (! is_file($file)) {
            return [];
        }

        $fields = require $file;

        if (! is_array($fields)) {
            throw new RuntimeException("Vertical template {$vertical->value} must return an array.");
        }

        return array_values(array_map(fn (mixed $field): array => $this->normalise($field, $vertical), $fields));
    }

    /**
     * @return TemplateField
     */
    private function normalise(mixed $field, Vertical $vertical): array
    {
        if (! is_array($field) || ! is_string($field['key'] ?? null) || ! is_string($field['label'] ?? null)) {
            throw new RuntimeException("Vertical template {$vertical->value} has a field without key/label.");
        }

        $type = FieldType::tryFrom(is_string($field['type'] ?? null) ? $field['type'] : '')
            ?? throw new RuntimeException("Vertical template {$vertical->value} field {$field['key']} has an unknown type.");

        $kind = $field['kind'] ?? null;

        if ($kind !== null && ! is_string($kind)) {
            throw new RuntimeException("Vertical template {$vertical->value} field {$field['key']} has an unknown kind.");
        }

        $options = $field['options'] ?? [];

        return [
            'key' => $field['key'],
            'label' => $field['label'],
            'type' => $type,
            'kind' => $kind === null ? null : (CategoryKind::tryFrom($kind) ?? throw new RuntimeException("Vertical template {$vertical->value} field {$field['key']} has an unknown kind.")),
            'is_filterable' => ($field['is_filterable'] ?? false) === true,
            'filter_order' => is_int($field['filter_order'] ?? null) ? $field['filter_order'] : 0,
            'sort_order' => is_int($field['sort_order'] ?? null) ? $field['sort_order'] : 0,
            'options' => is_array($options) ? array_values(array_filter($options, is_string(...))) : [],
        ];
    }
}
