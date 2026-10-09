<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Rules\EgyptianPhone;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST and PUT /api/store/addresses: `{ label, governorate, city, address_line, landmark?, phone?, is_default? }`
 * (BR-CU03). PUT replaces every field. The field rules and the row → AddressData mapping are shared with the
 * dashboard's customer requests, which carry `addresses[]`.
 */
final class SaveAddressRequest extends FormRequest
{
    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return self::fieldRules();
    }

    public function address(): AddressData
    {
        $row = [];

        foreach ($this->validated() as $key => $value) {
            $row[(string) $key] = $value;
        }

        return self::toData($row);
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public static function fieldRules(string $prefix = ''): array
    {
        return [
            $prefix.'label' => ['required', 'string', 'max:50'],
            $prefix.'governorate' => ['required', 'string', 'max:50'],
            $prefix.'city' => ['required', 'string', 'max:80'],
            $prefix.'address_line' => ['required', 'string', 'max:300'],
            $prefix.'landmark' => ['nullable', 'string', 'max:200'],
            $prefix.'phone' => ['nullable', 'string', 'max:30', new EgyptianPhone],
            $prefix.'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row  one validated address
     */
    public static function toData(array $row): AddressData
    {
        $phone = is_string($row['phone'] ?? null) ? PhoneNumber::toE164($row['phone']) : null;
        $landmark = is_string($row['landmark'] ?? null) ? mb_trim($row['landmark']) : '';

        return new AddressData(
            label: mb_trim(self::text($row, 'label')),
            governorate: mb_trim(self::text($row, 'governorate')),
            city: mb_trim(self::text($row, 'city')),
            addressLine: mb_trim(self::text($row, 'address_line')),
            landmark: $landmark === '' ? null : $landmark,
            phone: $phone,
            isDefault: filter_var($row['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN),
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function text(array $row, string $key): string
    {
        return is_string($row[$key] ?? null) ? $row[$key] : '';
    }
}
