<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Platform\Data\FieldTemplateData;
use App\Modules\Platform\Queries\VerticalTemplateQuery;
use Illuminate\Support\Facades\DB;

/**
 * BR-P09, D110: copies the active field templates of a vertical (platform data, V3 Task 01) and their options into
 * the tenant in context. Idempotent: existing keys and option labels are skipped, so re-running never duplicates
 * and never touches what the merchant changed.
 * The template's section becomes the field's `kind` (D69) while sections are still the CategoryKind enum; a
 * section key that is not a kind binds the field to no section. V3 Task 02 replaces this with tenant sections.
 * Registered on TenantCreatedHooks (runs inside CreateTenant's transaction, joining it).
 */
final readonly class ApplyVerticalTemplate
{
    public function __construct(
        private VerticalTemplateQuery $verticals,
        private AuditLogger $audit,
    ) {}

    /**
     * @return list<string> keys of the definitions that were created
     */
    public function handle(int $verticalId): array
    {
        $fields = $this->verticals->fieldsFor($verticalId);

        if ($fields === []) {
            return [];
        }

        return DB::transaction(function () use ($fields, $verticalId): array {
            $created = [];

            foreach ($fields as $field) {
                $definition = ProductFieldDefinition::query()->where('key', $field->key)->first();

                if ($definition === null) {
                    $definition = ProductFieldDefinition::query()->create($this->attributes($field));
                    $created[] = $field->key;
                }

                $existing = $definition->options()->pluck('label')->all();

                foreach ($field->options as $index => $label) {
                    if (! in_array($label, $existing, true)) {
                        $definition->options()->create(['label' => $label, 'sort_order' => $index + 1]);
                    }
                }
            }

            if ($created !== []) {
                $this->audit->log('catalog.template_applied', 'product_field_definitions', null, [
                    'vertical' => $this->verticals->find($verticalId)->key,
                    'fields' => $created,
                ]);
            }

            return $created;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(FieldTemplateData $field): array
    {
        return [
            'key' => $field->key,
            'label' => $field->label,
            'type' => $field->type,
            'kind' => $field->sectionKey === null ? null : CategoryKind::tryFrom($field->sectionKey),
            'is_required' => $field->isRequired,
            'is_filterable' => $field->isFilterable,
            'filter_order' => $field->filterOrder,
            'sort_order' => $field->sortOrder,
        ];
    }
}
