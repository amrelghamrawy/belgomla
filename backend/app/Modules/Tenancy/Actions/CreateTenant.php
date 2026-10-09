<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Data\NewTenantData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Exceptions\InvalidTenantInput;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantCreatedHooks;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Provisions a tenant with its settings row and first owner (Tech §18). Owns its transaction.
 */
final readonly class CreateTenant
{
    public function __construct(
        private TenantContext $context,
        private TenantCreatedHooks $hooks,
    ) {}

    public function handle(NewTenantData $data): Tenant
    {
        $subdomain = mb_strtolower(mb_trim($data->subdomain));

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $subdomain)) {
            throw InvalidTenantInput::subdomain();
        }

        if (in_array($subdomain, config()->array('tenancy.reserved_subdomains'), true)) {
            throw InvalidTenantInput::reserved($subdomain);
        }

        if (Tenant::query()->where('subdomain', $subdomain)->exists()) {
            throw InvalidTenantInput::duplicate($subdomain);
        }

        return DB::transaction(function () use ($subdomain, $data): Tenant {
            $tenant = Tenant::query()->create([
                'subdomain' => $subdomain,
                'name' => $data->name,
                'status' => TenantStatus::Active,
                'vertical' => $data->vertical,
            ]);

            $tenant->settings()->create([]);

            $this->context->runAs($tenant, function () use ($data, $tenant): void {
                TenantUser::query()->create([
                    'name' => $data->name,
                    'email' => mb_strtolower($data->ownerEmail),
                    'password' => $data->ownerPassword,
                    'role' => TenantUserRole::Owner,
                ]);

                $this->hooks->run($tenant);
            });

            return $tenant;
        });
    }
}
