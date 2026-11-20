<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Catalog\Actions\ApplyVerticalTemplate;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantCreatedHooks;
use Illuminate\Support\ServiceProvider;

/**
 * Catalog wiring: a new tenant gets its vertical's custom-field templates (BR-P09, D110), inside the
 * CreateTenant transaction and tenant context.
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(TenantCreatedHooks $hooks): void
    {
        $hooks->register(function (Tenant $tenant): void {
            $this->app->make(ApplyVerticalTemplate::class)->handle($tenant->vertical_id);
        });
    }
}
