<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Actions\ProvisionSections;
use App\Modules\Platform\Models\Vertical;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tenant> */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subdomain' => 'store'.fake()->unique()->numberBetween(1000, 999999),
            'name' => 'مكتبة '.fake()->lastName(),
            'status' => TenantStatus::Active,
            // D108 c: every tenant is in «مكتبات» (created by the V3 Task 01 migration) until another vertical exists.
            'vertical_id' => fn (): int => (Vertical::query()->where('key', 'libraries')->first()
                ?? VerticalFactory::new()->createOne(['key' => 'libraries', 'name' => 'مكتبات']))->id,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            if ($tenant->settings()->doesntExist()) {
                $tenant->settings()->create([]);
            }

            // D109 b, c: every store has its vertical's sections and the fallback «عام», as CreateTenant gives it
            // (without the field templates, which tests apply when they need them).
            app(TenantContext::class)->runAs($tenant, fn (): array => app(ProvisionSections::class)->handle($tenant->vertical_id));
        });
    }

    public function suspended(): static
    {
        return $this->state(['status' => TenantStatus::Suspended]);
    }
}
