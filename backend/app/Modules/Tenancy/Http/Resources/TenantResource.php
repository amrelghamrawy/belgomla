<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Platform\Data\VerticalData;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dashboard summary of the tenant itself (not its settings). The vertical (D108) is platform data, read by the
 * caller through Platform's VerticalTemplateQuery and handed in, since Tenancy never touches Platform models.
 *
 * @property-read Tenant $resource
 */
final class TenantResource extends JsonResource
{
    public function __construct(Tenant $resource, private readonly VerticalData $vertical)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'subdomain' => $this->resource->subdomain,
            'status' => $this->resource->status->value,
            'vertical' => ['key' => $this->vertical->key, 'name' => $this->vertical->name],
        ];
    }
}
