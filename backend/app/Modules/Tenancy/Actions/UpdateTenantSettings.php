<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Data\TenantSettingsData;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\DB;

/** BR-M: merchant edits branding, contact and ordering settings. Owns its transaction, audited. */
final readonly class UpdateTenantSettings
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Tenant $tenant, TenantSetting $settings, TenantSettingsData $data): TenantSetting
    {
        DB::transaction(function () use ($tenant, $settings, $data): void {
            if ($data->name !== null && $data->name !== $tenant->name) {
                $old = ['name' => $tenant->name];
                $tenant->update(['name' => $data->name]);
                $this->audit->log('tenant.renamed', $tenant, $old, ['name' => $data->name]);
            }

            $settings->fill($data->settingColumns());
            $dirty = $settings->getDirty();

            if ($dirty !== []) {
                $old = array_intersect_key($settings->getRawOriginal(), $dirty);
                $settings->save();
                $this->audit->log('settings.updated', $settings, $old, $dirty, entityId: $tenant->id);
            }
        });

        return $settings->refresh();
    }
}
