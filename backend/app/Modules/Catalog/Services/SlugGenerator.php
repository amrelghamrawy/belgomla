<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * URL slugs that keep Arabic letters (merchants share Arabic links), unique per tenant.
 * Collisions get "-2", "-3", ... appended; an input with no letters or digits becomes "item".
 */
final readonly class SlugGenerator
{
    public const string FALLBACK = 'item';

    /** Room kept at the end of the column for a "-NNN" collision suffix. */
    private const int SUFFIX_ROOM = 6;

    public function slugify(string $text): string
    {
        $text = (string) preg_replace('/[\p{M}\x{0640}]+/u', '', mb_strtolower($text));
        $slug = mb_trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $text), '-');

        return $slug === '' ? self::FALLBACK : $slug;
    }

    /**
     * @param  class-string<Category|Product>  $model
     */
    public function unique(string $model, string $text, int $maxLength, ?int $ignoreId = null): string
    {
        $base = mb_rtrim(mb_substr($this->slugify($text), 0, $maxLength - self::SUFFIX_ROOM), '-');
        $slug = $base;
        $attempt = 1;

        while ($this->taken($model, $slug, $ignoreId)) {
            $attempt++;
            $slug = "{$base}-{$attempt}";
        }

        return $slug;
    }

    /**
     * @param  class-string<Category|Product>  $model
     */
    private function taken(string $model, string $slug, ?int $ignoreId): bool
    {
        return $model::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
