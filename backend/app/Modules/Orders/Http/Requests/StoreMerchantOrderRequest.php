<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * POST /api/dashboard/orders: `{ customer_id, address_id, lines: [{ sku_id, quantity, discount? }], note?,
 * notify_customer?, override_mov?, discount? }` (BR-O11). Waiving the minimum order value needs `orders.override_mov`
 * (owner and manager, D84); anyone else sending `override_mov: true` gets 403. A line `discount` (`{ type, value }`)
 * and the order `discount` (`{ type, value, reason }`) follow PUT …/discount (D85): every role with `orders.create`
 * has `orders.discount`, and ApplyOrderDiscount holds a sales user to the store's cap.
 */
final class StoreMerchantOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->overrideMov() || Gate::allows(Permission::OrdersOverrideMov->value);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...QuoteOrderRequest::lineRules(),
            'lines.*' => ['array:sku_id,quantity,discount'],
            'lines.*.discount' => ['sometimes', 'nullable', 'array:type,value'],
            ...ApplyOrderDiscountRequest::discountRules('lines.*.discount', false),
            'address_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
            'notify_customer' => ['sometimes', 'boolean'],
            'override_mov' => ['sometimes', 'boolean'],
            'discount' => ['sometimes', 'nullable', 'array:type,value,reason'],
            ...ApplyOrderDiscountRequest::discountRules('discount', true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['discount.reason.required_with' => ApplyOrderDiscountRequest::REASON_REQUIRED];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->array('lines') as $index => $line) {
                if (is_array($line) && ApplyOrderDiscountRequest::isFullPercent($line['discount'] ?? null)) {
                    $validator->errors()->add("lines.{$index}.discount.value", ApplyOrderDiscountRequest::FULL_PERCENT);
                }
            }

            if (ApplyOrderDiscountRequest::isFullPercent($this->input('discount'))) {
                $validator->errors()->add('discount.value', ApplyOrderDiscountRequest::FULL_PERCENT);
            }
        }];
    }

    public function customerId(): int
    {
        return $this->integer('customer_id');
    }

    public function addressId(): int
    {
        return $this->integer('address_id');
    }

    /**
     * @return list<array{sku_id: int, quantity: int}>
     */
    public function lines(): array
    {
        return QuoteOrderRequest::linesOf($this->array('lines'));
    }

    /**
     * D85: sku id => the line's discount, for the lines that have one.
     *
     * @return array<int, array{type: DiscountType, value: string}>
     */
    public function lineDiscounts(): array
    {
        $discounts = [];

        foreach ($this->array('lines') as $line) {
            if (! is_array($line) || ! is_numeric($line['sku_id'] ?? null)) {
                continue;
            }

            [$type, $value] = ApplyOrderDiscountRequest::discountOf($line['discount'] ?? null);

            if ($type !== null && $value !== null) {
                $discounts[(int) $line['sku_id']] = ['type' => $type, 'value' => $value];
            }
        }

        return $discounts;
    }

    public function discountType(): ?DiscountType
    {
        return ApplyOrderDiscountRequest::discountOf($this->input('discount'))[0];
    }

    public function discountValue(): ?string
    {
        return ApplyOrderDiscountRequest::discountOf($this->input('discount'))[1];
    }

    public function discountReason(): ?string
    {
        $reason = mb_trim($this->string('discount.reason')->value());

        return $reason === '' || ! $this->discountType() instanceof DiscountType ? null : $reason;
    }

    public function note(): ?string
    {
        $note = mb_trim($this->string('note')->value());

        return $note === '' ? null : $note;
    }

    public function notifyCustomer(): bool
    {
        return $this->boolean('notify_customer');
    }

    public function overrideMov(): bool
    {
        return $this->boolean('override_mov');
    }

    public function actorId(): int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : 0;
    }
}
