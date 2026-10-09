<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Backs both `tenant:suspend` and `tenant:activate`.
 */
abstract class TenantStatusCommand extends Command
{
    abstract protected function targetStatus(): string;

    public function handle(TenantProvisioner $provisioner): int
    {
        try {
            $tenant = $provisioner->setStatus($this->argument('subdomain'), $this->targetStatus());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant \"{$tenant->subdomain}\" is now {$tenant->status}.");

        return self::SUCCESS;
    }
}
