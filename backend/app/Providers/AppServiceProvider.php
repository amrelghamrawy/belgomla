<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Messaging\Contracts\ConnectionAlerts;
use App\Modules\Messaging\Services\NullConnectionAlerts;
use App\Modules\Messaging\Services\ResetCodeMessages;
use App\Modules\Platform\Services\HealthProbe;
use App\Modules\Tenancy\Contracts\ResetCodeSender;
use App\Modules\Tenancy\Enums\Permission;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantCreatedHooks;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(TenantCreatedHooks::class);
        // D70: nobody is told about a dropped store number until Orders binds its dashboard notice.
        $this->app->bind(ConnectionAlerts::class, NullConnectionAlerts::class);
        // D112: Tenancy cannot depend on Messaging, so Messaging delivers the dashboard password reset code.
        $this->app->bind(ResetCodeSender::class, ResetCodeMessages::class);
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureDates();
        $this->configureCommands();
        $this->configureHealth();
        $this->configurePermissions();
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

    /** BR-T06, D82: one gate per permission, so policies can ask `Gate::allows('orders.manage')`. */
    private function configurePermissions(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (mixed $user): bool => $user instanceof TenantUser && $user->role->can($permission));
        }
    }

    /** `/up` checks the database and Redis, not only that PHP answers (tasks/11). */
    private function configureHealth(): void
    {
        Event::listen(DiagnosingHealth::class, fn () => $this->app->make(HealthProbe::class)->check());
    }
}
