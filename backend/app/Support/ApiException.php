<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * An error that maps directly onto the API error envelope (08-api-contract.md §0).
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $details
     * @param  list<array<string, mixed>>|null  $items
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $details = null,
        public readonly ?array $items = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'العنصر غير موجود'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self(403, $code, $message);
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function conflict(string $code, string $message, ?array $details = null): self
    {
        return new self(409, $code, $message, $details);
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @param  list<array<string, mixed>>|null  $items
     */
    public static function unprocessable(string $code, string $message, ?array $details = null, ?array $items = null): self
    {
        return new self(422, $code, $message, $details, $items);
    }

    public static function unavailable(string $code, string $message): self
    {
        return new self(503, $code, $message);
    }
}
