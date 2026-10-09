<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Exceptions;

use App\Support\ApiException;

/**
 * A message can only go to an Egyptian mobile number (010, 011, 012, 015). `422 invalid_phone`.
 */
final class InvalidPhoneNumber extends ApiException
{
    public static function for(string $phone): self
    {
        return new self(422, 'invalid_phone', 'رقم الموبايل غير صحيح', ['phone' => $phone]);
    }
}
