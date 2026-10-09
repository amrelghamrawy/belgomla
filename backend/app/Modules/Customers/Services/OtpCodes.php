<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

/**
 * BR-CU07, Tech §13: BelGomla generates and checks its own codes, so the messaging provider stays replaceable.
 * A code is 6 digits; only a keyed hash (HMAC with the app key) is stored, because a plain hash of a 6-digit
 * space is reversed by trying all of them.
 */
final readonly class OtpCodes
{
    public function generate(): string
    {
        return mb_str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    public function hash(string $code): string
    {
        return hash_hmac('sha256', $code, config()->string('app.key'));
    }

    public function matches(string $code, string $hash): bool
    {
        return hash_equals($hash, $this->hash($code));
    }
}
