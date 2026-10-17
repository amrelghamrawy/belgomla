<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Actions\CreateTenant;
use App\Modules\Tenancy\Data\NewTenantData;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\ApiException;
use App\Support\Console\TypedInput;
use Database\Seeders\DemoActivitySeeder;
use Database\Seeders\StorefrontDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A store to show BelGomla with, on any machine: a school-books tenant holding what `db:seed` gives the local
 * `alnoor` store, the demo catalog (StorefrontDemoSeeder) and two months of bookshops, orders and collections
 * (DemoActivitySeeder). Nothing reaches a phone: the demo customers are created without an invitation.
 * One transaction, so a failure leaves no half-filled tenant behind.
 */
final class TenantDemoCommand extends Command
{
    use TypedInput;

    protected $signature = 'tenant:demo
        {subdomain=demo : Subdomain under the base domain}
        {--name= : Store name (Arabic), default مكتبة النور}
        {--owner-email= : Owner email}
        {--owner-password= : Owner password}';

    protected $description = 'Create a demo tenant with the demo catalog, customers, orders and collections';

    public function handle(CreateTenant $createTenant, StorefrontDemoSeeder $catalog, DemoActivitySeeder $activity): int
    {
        try {
            $data = new NewTenantData(
                subdomain: $this->stringArgument('subdomain'),
                name: $this->stringOption('name', 'مكتبة النور') ?? 'مكتبة النور',
                ownerEmail: $this->stringOption('owner-email') ?? $this->askString('Owner email'),
                ownerPassword: $this->stringOption('owner-password') ?? $this->askString('Owner password', secret: true),
                vertical: Vertical::SchoolBooks,
            );

            $tenant = DB::transaction(function () use ($createTenant, $catalog, $activity, $data): Tenant {
                $tenant = $createTenant->handle($data);
                $catalog->setContainer($this->laravel)->__invoke(['subdomain' => $tenant->subdomain]);
                $activity->setContainer($this->laravel)->__invoke(['subdomain' => $tenant->subdomain]);

                return $tenant;
            });
        } catch (ApiException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Demo tenant #{$tenant->id} created: https://{$tenant->host()}");
        $this->line("Dashboard login: {$data->ownerEmail}");

        return self::SUCCESS;
    }
}
