<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/dashboard/orders: `{ customer_id, address_id, lines: [{ sku_id, quantity }], note?, notify_customer?,
 * override_mov? }` (BR-O11). Waiving the minimum order value needs `orders.override_mov` (owner and manager, D84);
 * anyone else sending `override_mov: true` gets 403.
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
            'address_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
            'notify_customer' => ['sometimes', 'boolean'],
            'override_mov' => ['sometimes', 'boolean'],
        ];
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
