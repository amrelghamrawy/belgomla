<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
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

    /** The new store name, when one was sent. */
    public function storeName(): ?string
    {
        return $this->has('name') ? $this->string('name')->value() : null;
    }
}
