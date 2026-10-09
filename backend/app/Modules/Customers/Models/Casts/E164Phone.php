<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models\Casts;

use App\Support\PhoneNumber;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A phone column stored in E.164 (`+201012345678`, BR-CU04): whatever a request or factory writes is normalised
 * through PhoneNumber, so the unique (tenant_id, phone) index sees one spelling per number. Requests validate
 * the number first; an invalid one reaching the model is a programming error.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class E164Phone implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return PhoneNumber::toE164($value) ?? throw new InvalidArgumentException("\"{$value}\" is not an Egyptian mobile number.");
    }
}
