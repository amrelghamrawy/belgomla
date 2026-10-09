<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\ProductFieldOption;

/**
 * BR-P08: options are archived, never deleted once used. Products that already hold the option keep
 * it; new products cannot pick it.
 */
final readonly class ArchiveFieldOption
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(ProductFieldOption $option): ProductFieldOption
    {
        if ($option->status === ArchiveStatus::Archived) {
            return $option;
        }

        $option->update(['status' => ArchiveStatus::Archived]);

        $this->audit->log('field_option.archived', $option, ['status' => ArchiveStatus::Active->value], ['status' => ArchiveStatus::Archived->value]);

        return $option;
    }
}
