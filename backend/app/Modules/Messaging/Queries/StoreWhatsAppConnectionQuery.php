<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Queries;

use App\Modules\Messaging\Enums\ConnectionStatus;
use App\Modules\Messaging\Models\WhatsAppConnection;
use Illuminate\Support\Collection;

/**
 * D70: lookups of store numbers. `current()` reads the current tenant's row. The other lookups serve wa-gateway
 * webhooks and the reconcile, which arrive without a tenant, so they span tenants by design (rules/00 "Never":
 * platform code); callers then work inside the connection's tenant.
 */
final readonly class StoreWhatsAppConnectionQuery
{
    public function current(): ?WhatsAppConnection
    {
        return WhatsAppConnection::query()->first();
    }

    public function bySessionKey(string $sessionKey): ?WhatsAppConnection
    {
        return WhatsAppConnection::withoutTenantScope()->with('tenant')->where('session_key', $sessionKey)->first();
    }

    /** Another store has this phone connected right now (tasks/12 lesson 2). */
    public function phoneConnectedElsewhere(string $phone, int $exceptId): bool
    {
        return WhatsAppConnection::withoutTenantScope()
            ->where('phone', $phone)
            ->where('status', ConnectionStatus::Connected)
            ->whereKeyNot($exceptId)
            ->exists();
    }

    /**
     * Every store number by status, keyed by session key.
     *
     * @return Collection<string, string>
     */
    public function sessionStatuses(): Collection
    {
        /** @var Collection<string, string> */
        return WhatsAppConnection::withoutTenantScope()->toBase()->pluck('status', 'session_key');
    }
}
