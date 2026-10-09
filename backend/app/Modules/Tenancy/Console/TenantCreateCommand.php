<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\TenantProvisioner;
use App\Support\Console\TypedInput;
use Illuminate\Console\Command;
use InvalidArgumentException;

class TenantCreateCommand extends Command
{
    use TypedInput;

    protected $signature = 'tenant:create
        {subdomain : Subdomain under the base domain, e.g. alnoor}
        {name : Store name (Arabic)}
        {--owner-email= : First owner email}
        {--owner-password= : First owner password}
        {--vertical=none : school-books | stationery | hardware | none}';

    protected $description = 'Create a new tenant with its settings and first owner user';

    public function handle(TenantProvisioner $provisioner): int
    {
        try {
            $email = $this->stringOption('owner-email') ?? $this->askString('Owner email');
            $password = $this->stringOption('owner-password') ?? $this->askString('Owner password', secret: true);

            $tenant = $provisioner->create(
                $this->stringArgument('subdomain'),
                $this->stringArgument('name'),
                $email,
                $password,
                $this->stringOption('vertical', 'none') ?? 'none',
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant #{$tenant->id} created: https://{$tenant->host()}");
        $this->line("Dashboard login: {$email}");

        return self::SUCCESS;
    }

    private function askString(string $question, bool $secret = false): string
    {
        $answer = $secret ? $this->secret($question) : $this->ask($question);

        if (! is_string($answer) || $answer === '') {
            throw new InvalidArgumentException("{$question} is required.");
        }

        return $answer;
    }
}
