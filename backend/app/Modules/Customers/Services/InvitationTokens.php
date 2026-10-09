<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * BR-CU08: the one-time invitation link. The token is 40 random characters; only its sha256 is stored, which
 * is enough for a value with that much entropy. The link points at the current tenant's storefront host.
 */
final readonly class InvitationTokens
{
    public function __construct(private TenantContext $context) {}

    public function generate(): string
    {
        return Str::random(40);
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function link(string $token): string
    {
        $host = $this->context->get()->subdomain.'.'.config()->string('tenancy.base_domain');

        return strtr(config()->string('customers.invitation.url'), ['{host}' => $host, '{token}' => $token]);
    }
}
