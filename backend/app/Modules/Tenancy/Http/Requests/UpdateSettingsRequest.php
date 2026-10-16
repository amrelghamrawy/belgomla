<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Rules\InvitationTemplate;
use App\Support\InvitationMessage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateSettingsRequest extends FormRequest
{
    /** @var list<string> */
    private const array FIELDS = [
        'name', 'primary_color', 'secondary_color', 'phone', 'whatsapp', 'address', 'description',
        'working_hours', 'mov_enabled', 'mov_amount', 'require_login_for_prices', 'require_customer_approval',
        'stale_pending_hours', 'invitation_message',
    ];

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'primary_color' => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'working_hours' => ['sometimes', 'nullable', 'array', 'max:7'],
            'working_hours.*.day' => ['required', 'integer', 'between:0,6'],
            'working_hours.*.from' => ['required', 'date_format:H:i'],
            'working_hours.*.to' => ['required', 'date_format:H:i'],
            'mov_enabled' => ['sometimes', 'boolean'],
            'mov_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999', 'required_if:mov_enabled,true'],
            'require_login_for_prices' => ['sometimes', 'boolean'],
            'require_customer_approval' => ['sometimes', 'boolean'],
            'stale_pending_hours' => ['sometimes', 'integer', 'between:1,720'],
            'invitation_message' => ['sometimes', 'nullable', 'string', 'max:'.InvitationMessage::MAX_LENGTH, new InvitationTemplate],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mov_amount.required_if' => 'يجب تحديد الحد الأدنى لقيمة الطلب عند تفعيله',
            'primary_color.regex' => 'اللون يجب أن يكون بصيغة #RRGGBB',
            'secondary_color.regex' => 'اللون يجب أن يكون بصيغة #RRGGBB',
        ];
    }

    /**
     * Which settings fields were present in the payload (so nullable ones can be cleared on purpose).
     *
     * @return list<string>
     */
    public function sentKeys(): array
    {
        return array_values(array_filter(self::FIELDS, $this->has(...)));
    }

    public function storeName(): ?string
    {
        return $this->has('name') ? $this->string('name')->value() : null;
    }

    public function color(string $field): ?string
    {
        return $this->has($field) ? mb_strtoupper($this->string($field)->value()) : null;
    }

    public function nullableString(string $field): ?string
    {
        if (! $this->filled($field)) {
            return null;
        }

        return $this->string($field)->value();
    }

    public function nullableBoolean(string $field): ?bool
    {
        return $this->has($field) ? $this->boolean($field) : null;
    }

    /** Money is carried as a 2-decimal string, never a float. */
    public function movAmount(): ?string
    {
        if (! $this->filled('mov_amount')) {
            return null;
        }

        return number_format($this->float('mov_amount'), 2, '.', '');
    }

    public function stalePendingHours(): ?int
    {
        return $this->has('stale_pending_hours') ? $this->integer('stale_pending_hours') : null;
    }

    /**
     * @return array<int, array{day: int, from: string, to: string}>|null
     */
    public function workingHours(): ?array
    {
        if (! $this->filled('working_hours')) {
            return null;
        }

        $hours = [];

        foreach ($this->array('working_hours') as $row) {
            if (! is_array($row)) {
                continue;
            }

            $day = $row['day'] ?? 0;
            $from = $row['from'] ?? '';
            $to = $row['to'] ?? '';

            $hours[] = [
                'day' => is_numeric($day) ? (int) $day : 0,
                'from' => is_string($from) ? $from : '',
                'to' => is_string($to) ? $to : '',
            ];
        }

        return $hours;
    }
}
