<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Platform\Data\FieldTemplateData;
use App\Modules\Platform\Queries\VerticalTemplateQuery;
use Illuminate\Support\Facades\DB;

/**
 * BR-P09, D109, D110: gives the tenant in context its vertical's sections (ProvisionSections), then copies the
 * vertical's active field templates and their options, each field in the tenant section of its template and linked
 * to it (`template_id`). Idempotent: existing keys and option labels are skipped, so re-running never duplicates
 * and never touches what the merchant changed.
 * Registered on TenantCreatedHooks (runs inside CreateTenant's transaction, joining it).
 */
final readonly class ApplyVerticalTemplate
{
    public function __construct(
        private VerticalTemplateQuery $verticals,
        private ProvisionSections $sections,
        private AuditLogger $audit,
    ) {}

    /**
     * @return list<string> keys of the definitions that were created
     */
    public function handle(int $verticalId): array
    {
        return DB::transaction(function () use ($verticalId): array {
            $sections = $this->sections->handle($verticalId);
            $fields = $this->verticals->fieldsFor($verticalId);
            $created = [];

            foreach ($fields as $field) {
                $definition = ProductFieldDefinition::query()->where('key', $field->key)->first();

                if ($definition === null) {
                    $definition = ProductFieldDefinition::query()->create($this->attributes($field, $sections));
                    $created[] = $field->key;
                } elseif ($definition->template_id === null) {
                    $definition->forceFill(['template_id' => $field->id])->save(); // D110 b: the link only, never the merchant's edits
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
     * @param  array<string, int>  $sections  the tenant's section ids by key
     * @return array<string, mixed>
     */
    private function attributes(FieldTemplateData $field, array $sections): array
    {
        return [
            'key' => $field->key,
            'label' => $field->label,
            'type' => $field->type,
            'section_id' => $field->sectionKey === null ? null : ($sections[$field->sectionKey] ?? null),
            'template_id' => $field->id,
            'is_required' => $field->isRequired,
            'is_filterable' => $field->isFilterable,
            'filter_order' => $field->filterOrder,
            'sort_order' => $field->sortOrder,
        ];
    }
}
