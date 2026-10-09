<?php

namespace Database\Seeders;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development seed: one school-books tenant.
     * Dashboard: owner@alnoor.test / password
     */
    public function run(): void
    {
        if (Tenant::query()->where('subdomain', 'alnoor')->exists()) {
            return;
        }

        app(TenantProvisioner::class)->create(
            subdomain: 'alnoor',
            name: 'مكتبة النور',
            ownerEmail: 'owner@alnoor.test',
            ownerPassword: 'password',
            vertical: 'school-books',
        );
    }
}
