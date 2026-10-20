<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Modules\Tenancy\Rules\InvitationTemplate;
use App\Modules\Tenancy\Rules\OrderStatusTemplate;
use App\Support\InvitationMessage;
use App\Support\Money;
use App\Support\OrderStatusMessage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateSettingsRequest extends FormRequest
{
    /** @var list<string> */
    private const array FIELDS = [
        'name', 'primary_color', 'secondary_color', 'phone', 'whatsapp', 'address', 'description',
        'working_hours', 'mov_enabled', 'mov_amount', 'require_login_for_prices', 'require_customer_approval',
        'stale_pending_hours', 'invitation_message', 'default_payment_terms_days', 'default_credit_limit',
        'order_notify_statuses', 'order_status_messages', 'pending_expiry_hours', 'max_discount_percent_sales',
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
            'default_payment_terms_days' => ['sometimes', 'nullable', 'integer', 'between:0,365'],
            'default_credit_limit' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'order_notify_statuses' => ['sometimes', 'array'],
            'order_notify_statuses.*' => ['string', 'distinct', 'in:'.implode(',', OrderStatusMessage::NOTIFIABLE)],
            'order_status_messages' => ['sometimes', 'array:'.implode(',', array_keys(OrderStatusMessage::DEFAULTS))],
            'order_status_messages.*' => ['nullable', 'string', 'max:'.OrderStatusMessage::MAX_LENGTH, new OrderStatusTemplate],
            'pending_expiry_hours' => ['sometimes', 'nullable', 'integer', 'between:1,720'],
            'max_discount_percent_sales' => ['sometimes', 'nullable', 'decimal:0,2', 'gt:0', 'lt:100'],
        ];
    }

    /**
     * BR-INV07, D81: an order is highlighted as stale before it can expire, so the expiry (when on) is never shorter
     * than `stale_pending_hours`, comparing what was sent with what is stored.
     *
     * @return list<callable(Validator): void>
     */
    public function after(CurrentTenantSettingsQuery $settings): array
    {
        return [function (Validator $validator) use ($settings): void {
            if ($validator->errors()->hasAny(['pending_expiry_hours', 'stale_pending_hours'])
                || (! $this->has('pending_expiry_hours') && ! $this->has('stale_pending_hours'))) {
                return;
            }

            $stored = $settings->get();
            $expiry = $this->has('pending_expiry_hours') ? $this->pendingExpiryHours() : $stored->pending_expiry_hours;
            $stale = $this->stalePendingHours() ?? $stored->stale_pending_hours;

            if ($expiry !== null && $expiry < $stale) {
                $validator->errors()->add('pending_expiry_hours', "يجب ألا تقل مهلة الإلغاء التلقائي عن مدة تمييز الطلب كمتأخر ({$stale} ساعة)");
            }
        }];
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

    public function nullableInteger(string $field): ?int
    {
        return $this->filled($field) ? $this->integer($field) : null;
    }

    /** A nullable money field as "150.00" (bcmath, never a float). */
    public function nullableMoney(string $field): ?string
    {
        return $this->filled($field) ? Money::normalize($this->string($field)->value()) : null;
    }

    /**
     * BR-N03: the statuses to notify, in the platform's order, without duplicates.
     *
     * @return list<string>|null
     */
    public function orderNotifyStatuses(): ?array
    {
        if (! $this->has('order_notify_statuses')) {
            return null;
        }

        $sent = array_map(strval(...), array_filter($this->array('order_notify_statuses'), is_string(...)));

        return array_values(array_intersect(OrderStatusMessage::NOTIFIABLE, $sent));
    }

    /**
     * BR-N03: status => template; an empty text or the default text sent back is stored as null (= the default).
     *
     * @return array<string, string|null>|null
     */
    public function orderStatusMessages(): ?array
    {
        if (! $this->has('order_status_messages')) {
            return null;
        }

        $messages = [];

        foreach ($this->array('order_status_messages') as $status => $text) {
            $text = is_string($text) ? mb_trim($text) : '';
            $messages[(string) $status] = $text === '' || $text === OrderStatusMessage::DEFAULTS[(string) $status] ? null : $text;
        }

        return array_filter($messages, fn (?string $text): bool => $text !== null);
    }

    /** BR-INV07: hours before an unconfirmed order cancels itself; null = off (also when sent empty). */
    public function pendingExpiryHours(): ?int
    {
        return $this->nullableInteger('pending_expiry_hours');
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
