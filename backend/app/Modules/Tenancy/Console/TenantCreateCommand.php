<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use InvalidArgumentException;

class TenantCreateCommand extends Command
{
    protected $signature = 'tenant:create
        {subdomain : Subdomain under the base domain, e.g. alnoor}
        {name : Store name (Arabic)}
        {--owner-email= : First owner email}
        {--owner-password= : First owner password}
        {--vertical=none : school-books | stationery | hardware | none}';

    protected $description = 'Create a new tenant with its settings and first owner user';

    public function handle(TenantProvisioner $provisioner): int
    {
        $email = $this->option('owner-email') ?: $this->ask('Owner email');
        $password = $this->option('owner-password') ?: $this->secret('Owner password');

        try {
            $tenant = $provisioner->create(
                $this->argument('subdomain'),
                $this->argument('name'),
                $email,
                $password,
                $this->option('vertical'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant #{$tenant->id} created: https://{$tenant->host()}");
        $this->line("Dashboard login: {$email}");

        return self::SUCCESS;
    }
}
