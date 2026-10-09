<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates a tenant with its settings row and first owner (Tech §18).
 * Vertical templates (custom fields) are applied by the Catalog module through the
 * `tenant.created` hook once that module exists.
 */
class TenantProvisioner
{
    /** @var array<callable(Tenant): void> */
    private array $afterCreate = [];

    public function __construct(private readonly TenantContext $context) {}

    public function onCreated(callable $callback): void
    {
        $this->afterCreate[] = $callback;
    }

    public function create(string $subdomain, string $name, string $ownerEmail, string $ownerPassword, string $vertical = 'none'): Tenant
    {
        $subdomain = mb_strtolower(mb_trim($subdomain));

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $subdomain)) {
            throw new InvalidArgumentException('Subdomain must be 1-63 chars of a-z, 0-9 and hyphens.');
        }
        if (in_array($subdomain, config()->array('tenancy.reserved_subdomains'), true)) {
            throw new InvalidArgumentException("Subdomain \"{$subdomain}\" is reserved.");
        }
        if (! in_array($vertical, Tenant::VERTICALS, true)) {
            throw new InvalidArgumentException('Vertical must be one of: '.implode(', ', Tenant::VERTICALS));
        }
        if (Tenant::query()->where('subdomain', $subdomain)->exists()) {
            throw new InvalidArgumentException("Subdomain \"{$subdomain}\" already exists.");
        }

        return DB::transaction(function () use ($subdomain, $name, $ownerEmail, $ownerPassword, $vertical) {
            $tenant = Tenant::create([
                'subdomain' => $subdomain,
                'name' => $name,
                'status' => Tenant::STATUS_ACTIVE,
                'vertical' => $vertical,
            ]);

            $tenant->settings()->create([]);

            $this->context->runAs($tenant, function () use ($ownerEmail, $ownerPassword, $name): void {
                TenantUser::create([
                    'name' => $name,
                    'email' => mb_strtolower($ownerEmail),
                    'password' => $ownerPassword,
                    'role' => TenantUser::ROLE_OWNER,
                ]);
            });

            foreach ($this->afterCreate as $callback) {
                $this->context->runAs($tenant, fn () => $callback($tenant));
            }

            return $tenant;
        });
    }

    public function setStatus(string $subdomain, string $status): Tenant
    {
        $tenant = Tenant::query()->where('subdomain', mb_strtolower($subdomain))->first();

        if ($tenant === null) {
            throw new InvalidArgumentException("Tenant \"{$subdomain}\" not found.");
        }

        $tenant->update(['status' => $status]);

        return $tenant;
    }
}
