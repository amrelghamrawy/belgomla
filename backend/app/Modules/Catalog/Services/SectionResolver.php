<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Section;
use RuntimeException;

/**
 * Finds the section a request names, inside the tenant in context (BR-T04, D109): by `section_id`, or by the old
 * `kind` (the section key, `general` = the fallback) that the dashboard sends until V3 Task 03. Only an active
 * section can be chosen; an unknown or another tenant's one is `422` «غير موجود», an archived one `422` «مؤرشف»,
 * on the field that named it.
 */
final readonly class SectionResolver
{
    /** Null when the request named no section. */
    public function chosen(?int $sectionId, ?string $kind): ?Section
    {
        if ($sectionId !== null) {
            return $this->active(Section::query()->find($sectionId), 'section_id');
        }

        if ($kind !== null) {
            return $this->active(Section::query()->where('key', $kind)->first(), 'kind');
        }

        return null;
    }

    /** D109 c: where a root category goes when no section is chosen. */
    public function fallback(): Section
    {
        return Section::query()->where('is_fallback', true)->first()
            ?? throw new RuntimeException('The store has no fallback section.');
    }

    private function active(?Section $section, string $field): Section
    {
        if (! $section instanceof Section) {
            throw InvalidCatalogInput::unknownReference($field);
        }

        return $section->status === ArchiveStatus::Active ? $section : throw InvalidCatalogInput::archivedReference($field);
    }
}
