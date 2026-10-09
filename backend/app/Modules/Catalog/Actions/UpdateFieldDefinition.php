<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\FieldDefinitionData;
use App\Modules\Catalog\Exceptions\FieldTypeLocked;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;

/**
 * BR-P08: label, flags and ordering can change freely; the type only while no product holds a value
 * for the field. A field that becomes text/number silently stops being filterable unless the request
 * explicitly asks for it to be filterable (then 422).
 */
final readonly class UpdateFieldDefinition
{
    private const array AUDITED = ['label', 'type', 'is_required', 'show_on_storefront', 'is_filterable', 'filter_order', 'sort_order'];

    public function __construct(private AuditLogger $audit) {}

    public function handle(ProductFieldDefinition $definition, FieldDefinitionData $data): ProductFieldDefinition
    {
        if ($data->type !== $definition->type && $this->hasValues($definition)) {
            throw FieldTypeLocked::make();
        }

        if ($data->isFilterable === true && ! $data->type->isFilterable()) {
            throw InvalidCatalogInput::notFilterable();
        }

        $old = $definition->only(self::AUDITED);

        $definition->update([
            'label' => $data->label,
            'type' => $data->type,
            'is_required' => $data->isRequired ?? $definition->is_required,
            'show_on_storefront' => $data->showOnStorefront ?? $definition->show_on_storefront,
            'is_filterable' => $data->type->isFilterable() && ($data->isFilterable ?? $definition->is_filterable),
            'filter_order' => $data->filterOrder ?? $definition->filter_order,
            'sort_order' => $data->sortOrder ?? $definition->sort_order,
        ]);

        $this->audit->log('field.updated', $definition, $old, $definition->only(self::AUDITED));

        return $definition->load('options');
    }

    /** The key is validated as [a-z0-9_] on create, so it is safe inside the JSON path. */
    private function hasValues(ProductFieldDefinition $definition): bool
    {
        return Product::query()->whereNotNull("custom_fields->{$definition->key}")->exists();
    }
}
