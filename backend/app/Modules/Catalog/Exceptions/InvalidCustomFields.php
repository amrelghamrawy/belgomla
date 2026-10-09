<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/**
 * BR-P08: custom field values failed validation against the tenant's definitions. Rendered as a
 * validation failure with one entry per field: `details["custom_fields.grade"] = ["..."]`.
 */
final class InvalidCustomFields extends ApiException
{
    /**
     * @param  array<string, string>  $errors  field key => message
     */
    public static function withErrors(array $errors): self
    {
        $details = [];

        foreach ($errors as $key => $message) {
            $details["custom_fields.{$key}"] = [$message];
        }

        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', $details);
    }
}
