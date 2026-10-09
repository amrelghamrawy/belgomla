<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Data\TransitionPlan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Models\Order;

/**
 * BR-O01 / 07-domain-model.md §11 as a pure decision: whether an order may move to a status and what moving
 * does. It reads the order and writes nothing; TransitionOrder and CancelOrder carry the plan out.
 *
 * `confirmed_at` is set the first time the order leaves `pending` forward, even when steps are skipped, so
 * a skipped confirmation still has a time.
 */
final readonly class OrderStateMachine
{
    public function plan(Order $order, OrderStatus $to): TransitionPlan
    {
        $from = $order->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidOrderTransition::from($from, $to);
        }

        if ($to === OrderStatus::Cancelled) {
            return new TransitionPlan($from, $to, ['cancelled_at'], releasesStock: true);
        }

        $timestamps = $order->confirmed_at === null ? ['confirmed_at'] : [];

        if ($to === OrderStatus::Delivered) {
            return new TransitionPlan($from, $to, [...$timestamps, 'delivered_at'], deductsStock: true);
        }

        return new TransitionPlan($from, $to, $timestamps);
    }
}
