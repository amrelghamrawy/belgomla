<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read TenantUser $resource
 */
final class TenantUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'role' => $this->resource->role->value,
            'status' => $this->resource->status->value,
            'last_login_at' => $this->resource->last_login_at?->toIso8601String(),
        ];
    }
}
