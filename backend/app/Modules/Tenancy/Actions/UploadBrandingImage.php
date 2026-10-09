<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Enums\BrandingImageKind;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Stores a logo/favicon under the tenant's folder, replaces the previous file, audits. */
final readonly class UploadBrandingImage
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Tenant $tenant, TenantSetting $settings, BrandingImageKind $kind, UploadedFile $file): TenantSetting
    {
        $path = $file->store("tenants/{$tenant->id}/branding", 'public');

        if ($path === false) {
            throw new RuntimeException('Could not store the uploaded branding image.');
        }

        $column = $kind->column();
        $previous = $settings->getAttribute($column);
        $old = is_string($previous) ? $previous : null;

        $settings->forceFill([$column => $path])->save();

        if ($old !== null && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        $this->audit->log("settings.{$kind->value}_changed", $settings, [$column => $old], [$column => $path], entityId: $tenant->id);

        return $settings;
    }
}
