<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;

/**
 * A JSONB column that is always a JSON object, never a list: an empty map is stored as `{}`, not `[]`,
 * so jsonb object functions (`jsonb_each_text`, `@>`) work on every row. Reads as an associative array.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
final class JsonObject implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $decoded = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : [];

        $map = [];

        foreach (is_array($decoded) ? $decoded : [] as $field => $fieldValue) {
            $map[(string) $field] = $fieldValue;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode((object) (is_array($value) ? $value : []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
