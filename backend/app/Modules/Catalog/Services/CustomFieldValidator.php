<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Exceptions\InvalidCustomFields;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use Illuminate\Database\Eloquent\Collection;

/**
 * BR-P08: validates a product's `custom_fields` map against the tenant's definitions and returns the
 * normalised map to store (select → option id, number → int|float, boolean → bool, text → trimmed).
 *
 * The input replaces the active values (PUT semantics; null or "" clears a value). Values of archived
 * definitions, and archived options a product already holds, are carried over from $current untouched
 * ("existing values are kept but hidden").
 *
 * @phpstan-type FieldValue int|float|string|bool
 */
final readonly class CustomFieldValidator
{
    public const int TEXT_MAX = 500;

    /**
     * @param  array<array-key, mixed>  $input
     * @param  array<string, FieldValue>  $current  the product's stored map (empty on create)
     * @return array<string, FieldValue>
     */
    public function validate(array $input, array $current = []): array
    {
        $definitions = $this->definitions();
        $values = [];
        $errors = [];

        foreach ($input as $key => $raw) {
            $key = (string) $key;
            $definition = $definitions->get($key);

            if (! $definition instanceof ProductFieldDefinition) {
                $errors[$key] = 'حقل غير معروف';

                continue;
            }

            if ($raw === null || $raw === '') {
                continue;
            }

            $result = $definition->status === ArchiveStatus::Archived
                ? $this->unchanged($raw, $current[$key] ?? null)
                : $this->value($definition, $raw, $current[$key] ?? null);

            if (is_string($result)) {
                $errors[$key] = $result;
            } else {
                $values[$key] = $result[0];
            }
        }

        foreach ($definitions as $key => $definition) {
            if ($definition->status === ArchiveStatus::Active && $definition->is_required && ! array_key_exists($key, $values) && ! isset($errors[$key])) {
                $errors[$key] = 'هذا الحقل مطلوب';
            }

            if ($definition->status === ArchiveStatus::Archived && array_key_exists($key, $current) && ! array_key_exists($key, $input)) {
                $values[$key] = $current[$key];
            }
        }

        if ($errors !== []) {
            throw InvalidCustomFields::withErrors($errors);
        }

        ksort($values);

        return $values;
    }

    /**
     * @return array{0: FieldValue}|string the normalised value, or an error message
     */
    private function value(ProductFieldDefinition $definition, mixed $raw, mixed $current): array|string
    {
        return match ($definition->type) {
            FieldType::Text => is_string($raw) && mb_strlen(mb_trim($raw)) <= self::TEXT_MAX
                ? [mb_trim($raw)]
                : 'يجب أن يكون نصًا لا يزيد عن '.self::TEXT_MAX.' حرف',
            FieldType::Number => is_int($raw) || is_float($raw) || (is_string($raw) && is_numeric($raw))
                ? [$this->number($raw)]
                : 'يجب أن يكون رقمًا',
            FieldType::Boolean => $this->boolean($raw) ?? 'يجب أن تكون القيمة نعم أو لا',
            FieldType::Select => $this->option($definition, $raw, $current),
        };
    }

    /**
     * An archived field accepts only the value the product already holds.
     *
     * @return array{0: FieldValue}|string
     */
    private function unchanged(mixed $raw, int|float|string|bool|null $current): array|string
    {
        return $current !== null && $current === $raw ? [$current] : 'هذا الحقل مؤرشف';
    }

    /** Whole numbers are stored as integers ("12", 12.0 → 12) so facet and equality filters match. */
    private function number(int|float|string $raw): int|float
    {
        if (is_string($raw)) {
            return filter_var($raw, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? (float) $raw;
        }

        return is_float($raw) && floor($raw) === $raw && abs($raw) < PHP_INT_MAX ? (int) $raw : $raw;
    }

    /**
     * @return array{0: bool}|null
     */
    private function boolean(mixed $raw): ?array
    {
        return match (true) {
            $raw === true, $raw === 1, $raw === '1', $raw === 'true' => [true],
            $raw === false, $raw === 0, $raw === '0', $raw === 'false' => [false],
            default => null,
        };
    }

    /**
     * @return array{0: int}|string
     */
    private function option(ProductFieldDefinition $definition, mixed $raw, mixed $current): array|string
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT);
        $option = $id === false ? null : $definition->options->firstWhere('id', $id);

        if ($option === null) {
            return 'الاختيار غير موجود في هذا الحقل';
        }

        if ($option->status === ArchiveStatus::Archived && $current !== $option->id) {
            return 'هذا الاختيار مؤرشف';
        }

        return [$option->id];
    }

    /**
     * @return Collection<string, ProductFieldDefinition>
     */
    private function definitions(): Collection
    {
        return ProductFieldDefinition::query()->with('options')->get()->keyBy('key');
    }
}
