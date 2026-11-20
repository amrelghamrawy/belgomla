<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Support\ApiException;

/** Provisioning input problems (CLI only in V1, so messages are English). */
final class InvalidTenantInput extends ApiException
{
    public static function subdomain(): self
    {
        return new self(422, 'invalid_subdomain', 'Subdomain must be 1-63 chars of a-z, 0-9 and hyphens.');
    }

    public static function reserved(string $subdomain): self
    {
        return new self(422, 'reserved_subdomain', "Subdomain \"{$subdomain}\" is reserved.");
    }

    public static function duplicate(string $subdomain): self
    {
        return new self(409, 'subdomain_taken', "Subdomain \"{$subdomain}\" already exists.");
    }

    /**
     * @param  list<string>  $activeKeys
     */
    public static function vertical(array $activeKeys): self
    {
        return new self(422, 'invalid_vertical', 'Vertical must be one of: '.implode(', ', $activeKeys));
    }

    public static function unknownTenant(string $subdomain): self
    {
        return new self(404, 'not_found', "Tenant \"{$subdomain}\" not found.");
    }
}
