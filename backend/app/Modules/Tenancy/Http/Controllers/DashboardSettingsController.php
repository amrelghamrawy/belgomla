<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Audit\AuditLogger;
use App\Modules\Tenancy\Http\Requests\UpdateSettingsRequest;
use App\Modules\Tenancy\Http\Requests\UploadBrandingImageRequest;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
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
        return ApiResponse::data($this->payload($this->context->get()->settingsRow()));
    }

    /** PUT /api/dashboard/settings */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $tenant = $this->context->get();
        $settings = $tenant->settingsRow();
        $name = $request->storeName();

        /** @var array<string, mixed> $incoming */
        $incoming = $request->validated();

        DB::transaction(function () use ($tenant, $settings, $incoming, $name): void {
            if ($name !== null && $name !== $tenant->name) {
                $old = ['name' => $tenant->name];
                $tenant->update(['name' => $name]);
                $this->audit->log('tenant.renamed', $tenant, $old, ['name' => $name]);
            }

            $settings->fill(array_intersect_key($incoming, array_flip(TenantSetting::EDITABLE)));
            $dirty = $settings->getDirty();

            if ($dirty !== []) {
                $old = array_intersect_key($settings->getRawOriginal(), $dirty);
                $settings->save();
                $this->audit->log('settings.updated', $settings, $old, $dirty, entityId: $tenant->id);
            }
        });

        return ApiResponse::data($this->payload($settings->refresh()));
    }

    /** POST /api/dashboard/settings/logo  |  POST /api/dashboard/settings/favicon */
    public function uploadImage(UploadBrandingImageRequest $request, string $kind): JsonResponse
    {
        if (! in_array($kind, ['logo', 'favicon'], true)) {
            throw ApiException::notFound();
        }

        $tenant = $this->context->get();
        $settings = $tenant->settingsRow();
        $column = $kind.'_path';

        $path = $request->image()->store("tenants/{$tenant->id}/branding", 'public');

        if ($path === false) {
            throw new ApiException(500, 'upload_failed', 'تعذر حفظ الملف');
        }

        $previous = $settings->getAttribute($column);
        $old = is_string($previous) ? $previous : null;

        $settings->forceFill([$column => $path])->save();

        if ($old !== null && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        $this->audit->log("settings.{$kind}_changed", $settings, [$column => $old], [$column => $path], entityId: $tenant->id);

        return ApiResponse::data($this->payload($settings));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TenantSetting $settings): array
    {
        return $settings->toPublicArray($this->context->get()) + [
            'stale_pending_hours' => $settings->stale_pending_hours,
        ];
    }
}
