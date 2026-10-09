<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Tenancy\Services\TenantCreatedHooks;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(TenantCreatedHooks::class);
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureDates();
        $this->configureCommands();
    }

    /** Input safety comes from Requests + Data classes (rules/02-architecture.md §3), not from $fillable. */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::unguard();
    }

    private function configureDates(): void
    {
        Date::use(CarbonImmutable::class);
    }

    private function configureCommands(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
