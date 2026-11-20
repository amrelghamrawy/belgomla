<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Tenancy\Actions\CreateTenant;
use App\Modules\Tenancy\Data\NewTenantData;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * Local development seed: one «مكتبات» tenant (its vertical's field templates are applied at creation, BR-P09) with
     * a demo catalog of books, school supplies and toys (StorefrontDemoSeeder, which adds a toys field) and two months of customers, orders and collections (DemoActivitySeeder).
     * Dashboard: owner@alnoor.test / password, and one user per staff role (manager@, sales@, warehouse@,
     * accountant@alnoor.test) with the same password.
     */
    public function run(CreateTenant $createTenant): void
    {
        if (Tenant::query()->where('subdomain', 'alnoor')->doesntExist()) {
            $createTenant->handle(new NewTenantData(
                subdomain: 'alnoor',
                name: 'مكتبة النور',
                ownerEmail: 'owner@alnoor.test',
                ownerPassword: 'password',
                verticalKey: 'libraries',
            ));
        }

        $this->call(StorefrontDemoSeeder::class);
        $this->call(DemoActivitySeeder::class, parameters: ['staffPassword' => 'password']);
    }
}
