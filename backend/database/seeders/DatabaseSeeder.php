<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Tenancy\Actions\CreateTenant;
use App\Modules\Tenancy\Data\NewTenantData;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * Local development seed: one school-books tenant.
     * Dashboard: owner@alnoor.test / password
     */
    public function run(CreateTenant $createTenant): void
    {
        if (Tenant::query()->where('subdomain', 'alnoor')->exists()) {
            return;
        }

        $createTenant->handle(new NewTenantData(
            subdomain: 'alnoor',
            name: 'مكتبة النور',
            ownerEmail: 'owner@alnoor.test',
            ownerPassword: 'password',
            vertical: Vertical::SchoolBooks,
        ));
    }
}
