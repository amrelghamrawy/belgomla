<?php

declare(strict_types=1);

namespace App\Modules\Platform\Queries;

use App\Modules\Platform\Data\FieldTemplateData;
use App\Modules\Platform\Data\SectionTemplateData;
use App\Modules\Platform\Data\VerticalData;
use App\Modules\Platform\Enums\VerticalStatus;
use App\Modules\Platform\Models\Vertical;
use App\Modules\Platform\Models\VerticalFieldTemplate;
use App\Modules\Platform\Models\VerticalFieldTemplateOption;
use App\Modules\Platform\Models\VerticalSection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * The only way other modules read the platform verticals (D108, D110): DTOs, never the Platform models.
 */
final readonly class VerticalTemplateQuery
{
    /** An active vertical by key, or null when it is unknown or archived (D108 e: not offered to new tenants). */
    public function activeByKey(string $key): ?VerticalData
    {
        $vertical = Vertical::query()->where('key', $key)->where('status', VerticalStatus::Active)->first();

        return $vertical === null ? null : $this->toData($vertical);
    }

    /**
     * @return list<string> the keys of the active verticals, in display order
     */
    public function activeKeys(): array
    {
        return array_values(Vertical::query()
            ->where('status', VerticalStatus::Active)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Vertical $vertical): string => $vertical->key)
            ->all());
    }

    /** A vertical by id, archived or not (a tenant keeps an archived vertical, D108 e). */
    public function find(int $id): VerticalData
    {
        $vertical = Vertical::query()->find($id) ?? throw new RuntimeException("Vertical #{$id} does not exist.");

        return $this->toData($vertical);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, VerticalData> keyed by id
     */
    public function findMany(array $ids): array
    {
        return Vertical::query()->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Vertical $vertical): array => [$vertical->id => $this->toData($vertical)])
            ->all();
    }

    /**
     * D109 a: the active default sections of a vertical, in display order.
     *
     * @return list<SectionTemplateData>
     */
    public function sectionsFor(int $verticalId): array
    {
        return array_values(VerticalSection::query()
            ->where('vertical_id', $verticalId)
            ->where('status', VerticalStatus::Active)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (VerticalSection $section): SectionTemplateData => new SectionTemplateData(
                $section->id, $section->key, $section->name, $section->reference_price_label, $section->icon, $section->sort_order,
            ))
            ->all());
    }

    /**
     * BR-P09: the active field templates of a vertical, with their options. A template bound to an archived
     * section is left out with it.
     *
     * @return list<FieldTemplateData>
     */
    public function fieldsFor(int $verticalId): array
    {
        $templates = VerticalFieldTemplate::query()
            ->with(['section', 'options' => fn (HasMany $query): HasMany => $query->orderBy('sort_order')->orderBy('id')])
            ->where('vertical_id', $verticalId)
            ->where('status', VerticalStatus::Active)
            ->orderBy('id')
            ->get()
            ->reject(fn (VerticalFieldTemplate $template): bool => $template->section?->status === VerticalStatus::Archived);

        return array_values($templates->map(fn (VerticalFieldTemplate $template): FieldTemplateData => new FieldTemplateData(
            id: $template->id,
            key: $template->key,
            label: $template->label,
            type: $template->type,
            sectionKey: $template->section?->key,
            isRequired: $template->is_required,
            isFilterable: $template->is_filterable,
            filterOrder: $template->filter_order,
            sortOrder: $template->sort_order,
            options: array_values($template->options->map(fn (VerticalFieldTemplateOption $option): string => $option->label)->all()),
        ))->all());
    }

    private function toData(Vertical $vertical): VerticalData
    {
        return new VerticalData($vertical->id, $vertical->key, $vertical->name, $vertical->status);
    }
}
