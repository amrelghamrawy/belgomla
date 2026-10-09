<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\TenantProvisioner;
use App\Support\Console\TypedInput;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Backs both `tenant:suspend` and `tenant:activate`.
 */
abstract class TenantStatusCommand extends Command
{
    use TypedInput;

    abstract protected function targetStatus(): string;

    public function handle(TenantProvisioner $provisioner): int
    {
        try {
            $tenant = $provisioner->setStatus($this->stringArgument('subdomain'), $this->targetStatus());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant \"{$tenant->subdomain}\" is now {$tenant->status}.");

        return self::SUCCESS;
    }
}
