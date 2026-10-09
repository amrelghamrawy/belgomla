<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/inventory/{sku}/adjust: `{ new_on_hand, reason }`, On Hand in base units (BR-U03). */
final class AdjustStockRequest extends FormRequest
{
    public const int MAX_ON_HAND = 10_000_000;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'new_on_hand' => ['required', 'integer', 'min:0', 'max:'.self::MAX_ON_HAND],
            'reason' => ['required', 'string', 'max:200'],
        ];
    }

    public function newOnHand(): int
    {
        return $this->integer('new_on_hand');
    }

    public function reason(): string
    {
        return mb_trim($this->string('reason')->value());
    }

    /** The dashboard user adjusting (inventory_movements.actor_id). */
    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
