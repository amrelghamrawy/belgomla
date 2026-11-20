<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\Section;
use Illuminate\Database\Eloquent\Collection;

/** The sections of the store in context (D109), in display order. The dashboard endpoints come with V3 Task 07. */
final readonly class SectionsQuery
{
    /**
     * @return Collection<int, Section>
     */
    public function get(): Collection
    {
        return Section::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @return array<string, string> section names by key
     */
    public function namesByKey(): array
    {
        return $this->get()->mapWithKeys(fn (Section $section): array => [$section->key => $section->name])->all();
    }
}
