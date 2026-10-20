<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Data\CancelData;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\NotificationType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Scopes\Stale;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BR-INV07, D81: the system cancels one unconfirmed order that outlived the store's `pending_expiry_hours`. Locks
 * the order and checks again that it is still pending and old enough (the merchant may have confirmed it, or turned
 * the setting off, since it was listed). An order with money collected is skipped and logged, never forced (BR-O03).
 * CancelOrder releases the stock and tells the customer; the merchant gets an `order_expired` notification.
 * Returns whether the order was cancelled.
 */
final readonly class ExpirePendingOrder
{
    public const string REASON = 'انتهت مهلة تأكيد الطلب';

    public function __construct(
        private CancelOrder $cancel,
        private NotifyMerchants $notify,
        private CurrentTenantSettingsQuery $settings,
    ) {}

    public function handle(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $hours = $this->settings->get()->pending_expiry_hours;

            if ($hours === null || $order->status !== OrderStatus::Pending || $order->isPreorder() || ! $order->placed_at->lessThan(Stale::threshold($hours))) {
                return false;
            }

            if (Money::compare($order->collected_amount, '0.00') > 0) {
                Log::warning('A pending order past its expiry has money collected and was left as is (BR-O03).', [
                    'tenant_id' => $order->tenant_id,
                    'order_number' => $order->number,
                    'collected_amount' => $order->collected_amount,
                ]);

                return false;
            }

            $order = $this->cancel->handle($order, new CancelData(self::REASON), CancelledBy::System);
            $this->notify->forOrder(NotificationType::OrderExpired, $order);

            return true;
        });
    }
}
