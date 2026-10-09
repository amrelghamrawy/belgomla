<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dashboard summary of the tenant itself (not its settings).
 *
 * @property-read Tenant $resource
 */
final class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'subdomain' => $this->resource->subdomain,
            'status' => $this->resource->status->value,
            'vertical' => $this->resource->vertical->value,
        ];
    }
}
