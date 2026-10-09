<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models\Scopes;

use App\Modules\Catalog\Enums\ArchiveStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Restrict an ArchiveStatus model (category, unit, field definition, field option) to active rows.
 */
final class Active
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function __invoke(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), ArchiveStatus::Active);
    }
}
