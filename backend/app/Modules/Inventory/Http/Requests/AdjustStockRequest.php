<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\AdjustmentReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * POST /api/dashboard/inventory/{sku}/adjust: `{ new_on_hand, reason, note?, expected_on_hand? }`, On Hand in base units (BR-U03), the
 * reason from the fixed list (D97; `import` is not the merchant's), a note required for `other`.
 */
final class AdjustStockRequest extends FormRequest
{
    public const int MAX_ON_HAND = 10_000_000;

    /**
     * @return array<string, list<string|In>>
     */
    public function rules(): array
    {
        return [
            'new_on_hand' => ['required', 'integer', 'min:0', 'max:'.self::MAX_ON_HAND],
            'reason' => ['required', 'string', Rule::in(AdjustmentReason::chosenByMerchant())],
            'note' => ['nullable', 'string', 'max:150', 'required_if:reason,'.AdjustmentReason::Other->value],
            'expected_on_hand' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_ON_HAND],
        ];
    }

    public function newOnHand(): int
    {
        return $this->integer('new_on_hand');
    }

    public function reason(): AdjustmentReason
    {
        return AdjustmentReason::from($this->string('reason')->value());
    }

    /** D99 a: the On Hand shown when the count began. */
    public function expectedOnHand(): ?int
    {
        return $this->filled('expected_on_hand') ? $this->integer('expected_on_hand') : null;
    }

    public function note(): ?string
    {
        $note = mb_trim($this->string('note')->value());

        return $note === '' ? null : $note;
    }

    /** The dashboard user adjusting (inventory_movements.actor_id). */
    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
