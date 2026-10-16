<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Exceptions;

use App\Support\ApiException;

/**
 * D70: a store has one number; linking another one starts with unlinking the current one.
 */
final class WhatsAppAlreadyConnected extends ApiException
{
    public static function make(): self
    {
        return new self(409, 'whatsapp_already_connected', 'رقم واتساب مربوط بالفعل، افصله أولًا لربط رقم آخر');
    }
}
