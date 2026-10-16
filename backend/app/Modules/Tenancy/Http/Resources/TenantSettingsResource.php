<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\TenantSetting;
use App\Support\InvitationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Store and dashboard view of a tenant's settings. Dashboard requests also get operational fields.
 *
 * @property-read TenantSetting $resource
 */
final class TenantSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = $this->resource->tenant;
        $dashboard = $request->is('api/dashboard/*');

        return [
            'name' => $tenant->name,
            'subdomain' => $tenant->subdomain,
            'logo_url' => $this->resource->logoUrl(),
            'favicon_url' => $this->resource->faviconUrl(),
            'colors' => ['primary' => $this->resource->primary_color, 'secondary' => $this->resource->secondary_color],
            'phone' => $this->resource->phone,
            'whatsapp' => $this->resource->whatsapp,
            'address' => $this->resource->address,
            'description' => $this->resource->description,
            'working_hours' => $this->resource->working_hours ?? [],
            'currency' => $this->resource->currency,
            'require_login_for_prices' => $this->resource->require_login_for_prices,
            'require_customer_approval' => $this->resource->require_customer_approval,
            'mov' => ['enabled' => $this->resource->mov_enabled, 'amount' => $this->resource->mov_amount],
            'stale_pending_hours' => $this->when($dashboard, $this->resource->stale_pending_hours),
            'invitation_message' => $this->when($dashboard, $this->resource->invitation_message),
            'invitation_message_default' => $this->when($dashboard, InvitationMessage::DEFAULT),
        ];
    }
}
