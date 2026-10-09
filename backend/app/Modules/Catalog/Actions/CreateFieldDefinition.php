<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\FieldDefinitionData;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** BR-P08: creates a tenant-defined custom field with its initial select options. Owns its transaction. */
final readonly class CreateFieldDefinition
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(FieldDefinitionData $data): ProductFieldDefinition
    {
        $key = $data->key ?? throw new RuntimeException('A new field definition needs a key.');

        if (ProductFieldDefinition::query()->where('key', $key)->exists()) {
            throw CatalogConflict::duplicateFieldKey();
        }

        if ($data->isFilterable === true && ! $data->type->isFilterable()) {
            throw InvalidCatalogInput::notFilterable();
        }

        if ($data->options !== [] && $data->type !== FieldType::Select) {
            throw InvalidCatalogInput::optionsNotAllowed();
        }

        return DB::transaction(function () use ($data, $key): ProductFieldDefinition {
            $definition = ProductFieldDefinition::query()->create([
                'key' => $key,
                'label' => $data->label,
                'type' => $data->type,
                'is_required' => $data->isRequired ?? false,
                'show_on_storefront' => $data->showOnStorefront ?? true,
                'is_filterable' => $data->isFilterable ?? false,
                'filter_order' => $data->filterOrder ?? 0,
                'sort_order' => $data->sortOrder ?? 0,
            ]);

            foreach ($data->options as $index => $label) {
                $definition->options()->create(['label' => $label, 'sort_order' => $index + 1]);
            }

            $this->audit->log('field.created', $definition, null, $definition->only([
                'key', 'label', 'type', 'is_required', 'show_on_storefront', 'is_filterable', 'filter_order', 'sort_order',
            ]) + ['options' => $data->options]);

            return $definition->refresh()->load('options');
        });
    }
}
