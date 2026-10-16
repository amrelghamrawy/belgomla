<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Data;

use App\Modules\Messaging\Models\WhatsAppConnection;

/**
 * What the dashboard shows about the store's WhatsApp number (D70): whether the feature is on, the stored
 * connection (null before the first link), the live QR while pairing, and after an unlink whether the phone
 * was told.
 */
final readonly class StoreWhatsAppStatus
{
    public function __construct(
        public bool $enabled,
        public ?WhatsAppConnection $connection,
        public ?string $qr = null,
        public ?bool $loggedOutOnPhone = null,
    ) {}
}
