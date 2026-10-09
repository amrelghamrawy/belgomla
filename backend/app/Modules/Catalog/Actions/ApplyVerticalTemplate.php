<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Services\VerticalTemplates;
use App\Modules\Tenancy\Enums\Vertical;
use Illuminate\Support\Facades\DB;

/**
 * BR-P09: inserts the vertical's field definitions and options for the tenant in context.
 * Idempotent: existing keys and option labels are skipped, so re-running never duplicates.
 * Registered on TenantCreatedHooks (runs inside CreateTenant's transaction, joining it).
 */
final readonly class ApplyVerticalTemplate
{
    public function __construct(
        private VerticalTemplates $templates,
        private AuditLogger $audit,
    ) {}

    /**
     * @return list<string> keys of the definitions that were created
     */
    public function handle(Vertical $vertical): array
    {
        $fields = $this->templates->fieldsFor($vertical);

        if ($fields === []) {
            return [];
        }

        return DB::transaction(function () use ($fields, $vertical): array {
            $created = [];

            foreach ($fields as $field) {
                $definition = ProductFieldDefinition::query()->where('key', $field['key'])->first();

                if ($definition === null) {
                    $definition = ProductFieldDefinition::query()->create(array_diff_key($field, ['options' => true]));
                    $created[] = $field['key'];
                }

                $existing = $definition->options()->pluck('label')->all();

                foreach ($field['options'] as $index => $label) {
                    if (! in_array($label, $existing, true)) {
                        $definition->options()->create(['label' => $label, 'sort_order' => $index + 1]);
                    }
                }
            }

            if ($created !== []) {
                $this->audit->log('catalog.template_applied', 'product_field_definitions', null, ['vertical' => $vertical->value, 'fields' => $created]);
            }

            return $created;
        });
    }
}
