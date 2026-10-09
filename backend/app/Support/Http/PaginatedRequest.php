<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for list endpoints (08-api-contract.md §0): ?page, ?per_page (max 100), ?sort=field|-field.
 * One of the few allowed abstract bases; subclasses add their filters to rules().
 */
abstract class PaginatedRequest extends FormRequest
{
    public const int DEFAULT_PER_PAGE = 24;

    public const int MAX_PER_PAGE = 100;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'sort' => ['sometimes', 'string', 'max:50', 'regex:/^-?[a-z_]+$/'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }

    /**
     * @return array{0: string, 1: 'asc'|'desc'}|null column and direction, or null when not sent
     */
    public function sort(): ?array
    {
        if (! $this->filled('sort')) {
            return null;
        }

        $sort = $this->string('sort')->value();

        return str_starts_with($sort, '-') ? [mb_substr($sort, 1), 'desc'] : [$sort, 'asc'];
    }
}
