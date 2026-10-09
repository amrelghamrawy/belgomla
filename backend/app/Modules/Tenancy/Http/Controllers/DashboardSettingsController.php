<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Audit\AuditLogger;
use App\Modules\Tenancy\Http\Requests\UpdateSettingsRequest;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DashboardSettingsController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/dashboard/settings */
    public function show(): JsonResponse
    {
        $tenant = $this->context->get();

        return ApiResponse::data($this->payload($tenant->settings));
    }

    /** PUT /api/dashboard/settings */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $tenant = $this->context->get();
        $settings = $tenant->settings;

        $incoming = $request->validated();

        DB::transaction(function () use ($tenant, $settings, $incoming) {
            if (array_key_exists('name', $incoming)) {
                $old = ['name' => $tenant->name];
                $tenant->update(['name' => $incoming['name']]);
                $this->audit->log('tenant.renamed', $tenant, $old, ['name' => $tenant->name]);
                unset($incoming['name']);
            }

            $settingValues = array_intersect_key($incoming, array_flip(TenantSetting::EDITABLE));

            if ($settingValues !== []) {
                $old = array_intersect_key($settings->getAttributes(), $settingValues);
                $settings->fill($settingValues)->save();
                $new = array_intersect_key($settings->getAttributes(), $settingValues);
                $this->audit->log('settings.updated', $settings, $old, $new, entityId: $tenant->id);
            }
        });

        return ApiResponse::data($this->payload($settings->refresh()));
    }

    /** POST /api/dashboard/settings/logo  |  POST /api/dashboard/settings/favicon */
    public function uploadImage(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['logo', 'favicon'], true), 404);

        $request->validate([
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,ico,svg', 'max:2048'],
        ]);

        $tenant = $this->context->get();
        $settings = $tenant->settings;
        $column = $kind.'_path';

        $path = $request->file('file')->store("tenants/{$tenant->id}/branding", 'public');

        $old = $settings->{$column};
        $settings->forceFill([$column => $path])->save();

        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        $this->audit->log("settings.{$kind}_changed", $settings, [$column => $old], [$column => $path], entityId: $tenant->id);

        return ApiResponse::data($this->payload($settings));
    }

    private function payload(TenantSetting $settings): array
    {
        $tenant = $this->context->get();

        return $settings->toPublicArray($tenant) + [
            'stale_pending_hours' => $settings->stale_pending_hours,
        ];
    }
}
