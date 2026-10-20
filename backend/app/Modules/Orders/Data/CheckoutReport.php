<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Exceptions\CheckoutFailed;

/**
 * What CheckoutValidator found for a list of lines (BR-PR06 steps 2–7), before anything is written: every line priced
 * (failing ones too, so a quote can show them), the problem of each failing SKU, the items total, the minimum order
 * value shortfall (BR-MOV02) and the credit limit shortfall (BR-C05). Checkout and PlaceOrder turn it into the order
 * or a `CheckoutFailed`; the merchant's quote shows it as is.
 */
final readonly class CheckoutReport
{
    /**
     * @param  list<CheckoutLine>  $lines  every line, in the order given
     * @param  array<int, CheckoutProblem>  $problems  keyed by sku_id
     * @param  array{required: string, current: string}|null  $mov
     * @param  array{limit: string, exposure: string, order_total: string}|null  $credit
     */
    public function __construct(
        public array $lines,
        public array $problems,
        public string $total,
        public ?string $movAmount,
        public ?array $mov,
        public ?array $credit,
    ) {}

    /** The problem of a SKU, if it has one. */
    public function problemFor(int $skuId): ?CheckoutProblem
    {
        return $this->problems[$skuId] ?? null;
    }

    /**
     * The checked lines, or the whole refusal at once. `$skipMov` lets a merchant order under the minimum order value
     * through (D84); nothing else can be skipped.
     *
     * @return list<CheckoutLine>
     */
    public function assertPasses(bool $skipMov = false): array
    {
        $mov = $skipMov ? null : $this->mov;

        if ($this->problems !== [] || $mov !== null || $this->credit !== null) {
            throw CheckoutFailed::with(array_values($this->problems), $mov, $this->credit);
        }

        return $this->lines;
    }
}
