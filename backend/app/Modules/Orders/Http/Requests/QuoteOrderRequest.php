<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/dashboard/orders/quote: `{ customer_id, lines: [{ sku_id, quantity }] }` (BR-O11). The merchant's order
 * form asks it after every change; nothing is saved.
 */
final class QuoteOrderRequest extends FormRequest
{
    public const int MAX_LINES = 200;

    /**
     * The customer and lines rules shared with StoreMerchantOrderRequest. Quantities in selling units; a SKU once.
     *
     * @return array<string, list<string>>
     */
    public static function lineRules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['array:sku_id,quantity'],
            'lines.*.sku_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /**
     * @param  array<mixed>  $lines  validated `lines`
     * @return list<array{sku_id: int, quantity: int}>
     */
    public static function linesOf(array $lines): array
    {
        /** @var list<array{sku_id: int|string, quantity: int|string}> $lines */
        return array_map(fn (array $line): array => ['sku_id' => (int) $line['sku_id'], 'quantity' => (int) $line['quantity']], $lines);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return self::lineRules();
    }

    public function customerId(): int
    {
        return $this->integer('customer_id');
    }

    /**
     * @return list<array{sku_id: int, quantity: int}>
     */
    public function lines(): array
    {
        return self::linesOf($this->array('lines'));
    }
}
