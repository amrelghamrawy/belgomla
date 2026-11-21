<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Actions\CreateTenant;
use App\Modules\Tenancy\Data\NewTenantData;
use App\Support\ApiException;
use App\Support\Console\TypedInput;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class TenantCreateCommand extends Command
{
    use TypedInput;

    protected $signature = 'tenant:create
        {subdomain : Subdomain under the base domain, e.g. alnoor}
        {name : Store name (Arabic)}
        {--owner-email= : First owner email}
        {--owner-password= : First owner password}
        {--owner-phone= : First owner Egyptian mobile, where a password reset code goes (D112); optional}
        {--vertical=libraries : Key of an active store vertical (D108); an unknown key lists the active ones}';

    protected $description = 'Create a new tenant with its settings and first owner user';

    public function handle(CreateTenant $createTenant): int
    {
        try {
            $email = $this->stringOption('owner-email') ?? $this->askString('Owner email');
            $password = $this->stringOption('owner-password') ?? $this->askString('Owner password', secret: true);

            $tenant = $createTenant->handle(new NewTenantData(
                subdomain: $this->stringArgument('subdomain'),
                name: $this->stringArgument('name'),
                ownerEmail: $email,
                ownerPassword: $password,
                verticalKey: $this->stringOption('vertical', 'libraries') ?? 'libraries',
                ownerPhone: $this->stringOption('owner-phone'),
            ));
        } catch (ApiException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant #{$tenant->id} created: https://{$tenant->host()}");
        $this->line("Dashboard login: {$email}");

        return self::SUCCESS;
    }
}
