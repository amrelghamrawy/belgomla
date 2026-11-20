<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Data\LineDiscountData;
use App\Modules\Pricing\Enums\DiscountType;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PUT /api/dashboard/orders/{number}/discount (BR-PR08, D85): `{ items: [{ order_item_id, type, value }],
 * order: { type, value, reason } | null }`, the whole discount state of the order. `type` is `amount` (EGP off) or
 * `percent` (below 100), null for none; a line once; an order discount needs a reason. The discount rules are shared
 * with StoreMerchantOrderRequest (the same controls on the new-order form).
 */
final class ApplyOrderDiscountRequest extends FormRequest
{
    public const string FULL_PERCENT = 'نسبة الخصم يجب أن تكون أقل من 100%';

    public const string REASON_REQUIRED = 'اكتب سبب الخصم على الطلب';

    /**
     * The rules of one discount block at `$prefix` (`{ type, value }`, plus `reason` for an order discount).
     *
     * @return array<string, list<string>>
     */
    public static function discountRules(string $prefix, bool $withReason): array
    {
        return [
            "{$prefix}.type" => ['nullable', "required_with:{$prefix}.value", 'string', 'in:amount,percent'],
            "{$prefix}.value" => ['nullable', "required_with:{$prefix}.type", 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            ...($withReason ? ["{$prefix}.reason" => ['nullable', "required_with:{$prefix}.type", 'string', 'max:300']] : []),
        ];
    }

    /** Whether a discount block asks for 100% or more off (a percent is always below 100). */
    public static function isFullPercent(mixed $block): bool
    {
        return is_array($block)
            && ($block['type'] ?? null) === 'percent'
            && is_numeric($block['value'] ?? null)
            && bccomp((string) $block['value'], '100', 2) >= 0;
    }

    /**
     * A validated discount block as [type, value]; [null, null] for none.
     *
     * @return array{0: DiscountType|null, 1: string|null}
     */
    public static function discountOf(mixed $block): array
    {
        if (! is_array($block) || ! is_string($block['type'] ?? null) || ! is_scalar($block['value'] ?? null)) {
            return [null, null];
        }

        return [DiscountType::from($block['type']), Money::normalize((string) $block['value'])];
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:200'],
            'items.*' => ['array:order_item_id,type,value'],
            'items.*.order_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            ...self::discountRules('items.*', false),
            'order' => ['sometimes', 'nullable', 'array:type,value,reason'],
            ...self::discountRules('order', true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['order.reason.required_with' => self::REASON_REQUIRED];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->array('items') as $index => $item) {
                if (self::isFullPercent($item)) {
                    $validator->errors()->add("items.{$index}.value", self::FULL_PERCENT);
                }
            }

            if (self::isFullPercent($this->input('order'))) {
                $validator->errors()->add('order.value', self::FULL_PERCENT);
            }
        }];
    }

    /**
     * @return list<LineDiscountData>
     */
    public function items(): array
    {
        /** @var list<array{order_item_id: int|string, type?: string|null, value?: string|int|float|null}> $items */
        $items = $this->array('items');

        return array_map(function (array $item): LineDiscountData {
            [$type, $value] = self::discountOf($item);

            return new LineDiscountData((int) $item['order_item_id'], $type, $value);
        }, $items);
    }

    public function orderType(): ?DiscountType
    {
        return self::discountOf($this->input('order'))[0];
    }

    public function orderValue(): ?string
    {
        return self::discountOf($this->input('order'))[1];
    }

    public function reason(): ?string
    {
        $reason = mb_trim($this->string('order.reason')->value());

        return $reason === '' || ! $this->orderType() instanceof DiscountType ? null : $reason;
    }

    public function actorId(): int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : 0;
    }
}
