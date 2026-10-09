<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Queries;

use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Database\Eloquent\Collection;

/** Users of the tenant in context, oldest first. */
final readonly class TenantUsersQuery
{
    /**
     * @return Collection<int, TenantUser>
     */
    public function get(): Collection
    {
        return TenantUser::query()->orderBy('id')->get();
    }
}
