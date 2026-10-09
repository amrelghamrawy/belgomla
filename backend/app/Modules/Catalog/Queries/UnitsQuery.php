<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Collection;

/** All units of the tenant, active first, then by name. */
final readonly class UnitsQuery
{
    /**
     * @return Collection<int, Unit>
     */
    public function get(): Collection
    {
        return Unit::query()->orderBy('status')->orderBy('name')->get();
    }
}
