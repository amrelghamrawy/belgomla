<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Models\ProductFieldOption;

/** Rename and/or reorder an option. Products store the option id, so a rename shows everywhere. */
final readonly class UpdateFieldOption
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(ProductFieldOption $option, ?string $label, ?int $sortOrder): ProductFieldOption
    {
        $duplicate = $label !== null && ProductFieldOption::query()
            ->where('definition_id', $option->definition_id)
            ->where('label', $label)
            ->whereKeyNot($option->id)
            ->exists();

        if ($duplicate) {
            throw CatalogConflict::duplicateOptionLabel();
        }

        $old = ['label' => $option->label, 'sort_order' => $option->sort_order];

        $option->update([
            'label' => $label ?? $option->label,
            'sort_order' => $sortOrder ?? $option->sort_order,
        ]);

        $this->audit->log('field_option.updated', $option, $old, ['label' => $option->label, 'sort_order' => $option->sort_order]);

        return $option;
    }
}
