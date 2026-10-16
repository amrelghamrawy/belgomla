<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Exceptions;

use App\Support\ApiException;

/**
 * D70: wa-gateway cannot take the request: it is down (`wa_gateway_unavailable`) or holds as many numbers as
 * the platform allows (`wa_gateway_capacity`). Both are `503`.
 */
final class WaGatewayUnavailable extends ApiException
{
    public static function down(): self
    {
        return new self(503, 'wa_gateway_unavailable', 'خدمة ربط واتساب غير متاحة الآن، حاول بعد قليل');
    }

    public static function full(): self
    {
        return new self(503, 'wa_gateway_capacity', 'لا يمكن ربط أرقام جديدة الآن، تواصل مع الدعم');
    }
}
