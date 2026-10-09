<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Actions\SetTenantStatus;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Support\ApiException;
use App\Support\Console\TypedInput;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Backs both `tenant:suspend` and `tenant:activate`. One of the few allowed abstract bases.
 */
abstract class TenantStatusCommand extends Command
{
    use TypedInput;

    abstract protected function targetStatus(): TenantStatus;

    public function handle(SetTenantStatus $setStatus): int
    {
        try {
            $tenant = $setStatus->handle($this->stringArgument('subdomain'), $this->targetStatus());
        } catch (ApiException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant \"{$tenant->subdomain}\" is now {$tenant->status->value}.");

        return self::SUCCESS;
    }
}
