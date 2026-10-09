<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\ProductFieldDefinition;

/**
 * BR-P08: deleting a definition is soft. Product values for the key are kept but hidden from the
 * store, facets and the product form; UpdateProduct carries them over untouched.
 */
final readonly class ArchiveFieldDefinition
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(ProductFieldDefinition $definition): ProductFieldDefinition
    {
        if ($definition->status === ArchiveStatus::Archived) {
            return $definition->load('options');
        }

        $definition->update(['status' => ArchiveStatus::Archived]);

        $this->audit->log('field.archived', $definition, ['status' => ArchiveStatus::Active->value], ['status' => ArchiveStatus::Archived->value]);

        return $definition->load('options');
    }
}
