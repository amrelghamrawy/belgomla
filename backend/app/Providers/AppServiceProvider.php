<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Tenancy\TenantContext;
use App\Modules\Tenancy\TenantProvisioner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(TenantProvisioner::class);
    }

    public function boot(): void
    {
        // Catch mass-assignment and lazy-loading mistakes early in development.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
