<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Enums\CategoryIcon;
use App\Modules\Catalog\Models\Section;
use App\Modules\Platform\Queries\VerticalTemplateQuery;

/**
 * D109 b, c: gives the tenant in context the active default sections of its vertical and its one fallback «عام»
 * (key `general`). Idempotent: a vertical section the tenant already has (by `vertical_section_id`, archived or
 * not), and a key or a name it already uses, are skipped, so it never touches what the store renamed or archived (D110 b).
 * A step: joins the caller's transaction (CreateTenant, ApplyVerticalTemplate).
 */
final readonly class ProvisionSections
{
    public const string FALLBACK_KEY = 'general';

    public function __construct(private VerticalTemplateQuery $verticals) {}

    /**
     * @return array<string, int> the tenant's section ids by key
     */
    public function handle(int $verticalId): array
    {
        $existing = Section::query()->get(['id', 'key', 'name', 'vertical_section_id', 'sort_order', 'is_fallback']);
        $linked = $existing->map(fn (Section $section): ?int => $section->vertical_section_id)->filter()->all();
        $keys = $existing->map(fn (Section $section): string => $section->key)->all();
        $names = $existing->map(fn (Section $section): string => $section->name)->all();
        $next = $existing->reduce(fn (int $max, Section $section): int => max($max, $section->sort_order), 0) + 1;

        foreach ($this->verticals->sectionsFor($verticalId) as $template) {
            if (in_array($template->id, $linked, true) || in_array($template->key, $keys, true) || in_array($template->name, $names, true)) {
                continue;
            }

            Section::query()->create([
                'vertical_section_id' => $template->id,
                'key' => $template->key,
                'name' => $template->name,
                'reference_price_label' => $template->referencePriceLabel,
                'icon' => $template->icon === null ? null : CategoryIcon::tryFrom($template->icon),
                'sort_order' => $next++,
            ]);
            $keys[] = $template->key;
        }

        if (! in_array(self::FALLBACK_KEY, $keys, true) && ! $existing->contains(fn (Section $section): bool => $section->is_fallback)) {
            Section::query()->create([
                'key' => self::FALLBACK_KEY,
                'name' => 'عام',
                'icon' => CategoryIcon::Package,
                'is_fallback' => true,
                'sort_order' => $next,
            ]);
        }

        return Section::query()->get(['id', 'key'])->mapWithKeys(fn (Section $section): array => [$section->key => $section->id])->all();
    }
}
