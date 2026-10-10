<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Platform\Services\HealthProbe;
use App\Modules\Tenancy\Services\TenantCreatedHooks;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        $this->configureHealth();
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

    /** `/up` checks the database and Redis, not only that PHP answers (tasks/11). */
    private function configureHealth(): void
    {
        Event::listen(DiagnosingHealth::class, fn () => $this->app->make(HealthProbe::class)->check());
    }
}
