<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Actions\NotifyCustomerOfStatus;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Data\CancelData;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/*
| BR-N03 with the log driver and Queue::fake(): a message shows as an `outbound_messages` row with its body.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->owner = $this->makeOwner();
    $this->customer = $this->makeCustomer(['name' => 'محمود', 'phone' => '+201012345678']);
    $this->order = $this->placeOrder($this->customer, [$this->orderableSku()->id => 6]);
    app(TenantContext::class)->set($this->tenant);
    OutboundMessage::query()->delete();

    $this->notify = fn (OrderStatus $status): mixed => app(NotifyCustomerOfStatus::class)->handle($this->order->refresh(), $status);
    $this->message = fn (): ?OutboundMessage => OutboundMessage::query()->where('purpose', MessagePurpose::OrderStatus)->latest('id')->first();
});

test('BR-N03 a listed status sends a whatsapp message about the order in the default words', function (): void {
    ($this->notify)(OrderStatus::Confirmed);

    expect(($this->message)()?->only(['to_phone', 'purpose', 'order_id', 'channel']))->toEqual([
        'to_phone' => '+201012345678', 'purpose' => MessagePurpose::OrderStatus, 'order_id' => $this->order->id, 'channel' => App\Modules\Messaging\Enums\Channel::WhatsApp,
    ])
        ->and(($this->message)()?->body)->toBe("أهلًا محمود، تم تأكيد طلبك رقم {$this->order->number} من مكتبة النور بإجمالي 540.00. سنبلغك عند خروجه للتوصيل.");
});

test('BR-N03 the store\'s own words are used', function (): void {
    $this->tenant->settings->update(['order_status_messages' => ['delivered' => 'وصلك طلب {رقم_الطلب} يا {اسم_العميل}']]);

    ($this->notify)(OrderStatus::Delivered);

    expect(($this->message)()?->body)->toBe("وصلك طلب {$this->order->number} يا محمود");
});

test('BR-N03 a status the store does not notify sends nothing', function (): void {
    ($this->notify)(OrderStatus::Preparing);

    expect(($this->message)())->toBeNull();
});

test('BR-N03 a customer who opted out gets nothing', function (): void {
    $this->customer->forceFill(['order_notifications_opt_out' => true])->save();

    ($this->notify)(OrderStatus::Confirmed);

    expect(($this->message)())->toBeNull();
});

test('BR-N03 nothing is sent when the transition rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            ($this->notify)(OrderStatus::Confirmed);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(($this->message)())->toBeNull();
});

test('D79 a customer over the order-message limit is skipped without failing', function (): void {
    foreach (range(1, 10) as $ignored) {
        RateLimiter::hit('messaging:phone:orders:'.$this->tenant->id.':+201012345678', 3600);
    }

    expect(fn () => ($this->notify)(OrderStatus::Confirmed))->not->toThrow(Throwable::class)
        ->and(($this->message)())->toBeNull();
});

test('BR-N03 confirming an order tells the customer', function (): void {
    app(TransitionOrder::class)->handle($this->order, new TransitionData(OrderStatus::Confirmed), $this->owner->id);

    expect(($this->message)()?->order_id)->toBe($this->order->id);
});

test('BR-N03 a merchant cancelling tells the customer with the reason, a customer cancelling does not', function (CancelledBy $by, bool $sent): void {
    app(CancelOrder::class)->handle($this->order, new CancelData('نفاد الكمية'), $by, $by === CancelledBy::Customer ? $this->customer->id : $this->owner->id);

    expect(($this->message)() !== null)->toBe($sent);
})->with(['merchant' => [CancelledBy::TenantUser, true], 'customer' => [CancelledBy::Customer, false]]);

test('BR-N03 the cancelled text carries the reason', function (): void {
    app(CancelOrder::class)->handle($this->order, new CancelData('نفاد الكمية'), CancelledBy::TenantUser, $this->owner->id);

    expect(($this->message)()?->body)->toEndWith('السبب: نفاد الكمية');
});

test('BR-N03 the message is sent in the order\'s tenant even when the caller left it before the commit', function (): void {
    DB::transaction(function (): void {
        ($this->notify)(OrderStatus::Confirmed);
        app(TenantContext::class)->clear();
    });

    expect(OutboundMessage::withoutTenantScope()->where('order_id', $this->order->id)->sole()->tenant_id)->toBe($this->tenant->id);
});

test('BR-N03 an order of another tenant is never visible here', function (): void {
    $theirs = $this->inTenant(fn (): Order => Order::factory()->create(), $this->otherTenant());

    expect(fn () => app(NotifyCustomerOfStatus::class)->handle($theirs, OrderStatus::Confirmed))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('BR-O11 the placed message is sent whatever statuses the store notifies, in the store\'s words when it has them', function (): void {
    $this->tenant->settings->update(['order_notify_statuses' => [], 'order_status_messages' => ['placed' => 'سجلنا طلبك {رقم_الطلب} يا {اسم_العميل} ({حالة_الطلب})']]);

    app(NotifyCustomerOfStatus::class)->placed($this->order->refresh());

    expect(($this->message)()?->body)->toBe("سجلنا طلبك {$this->order->number} يا محمود (قيد الانتظار)");
});

test('BR-O11 the placed message respects the customer\'s opt-out', function (): void {
    $this->customer->forceFill(['order_notifications_opt_out' => true])->save();

    app(NotifyCustomerOfStatus::class)->placed($this->order->refresh());

    expect(($this->message)())->toBeNull();
});

test('D85 a discount is told only when the store notifies discounts', function (): void {
    app(NotifyCustomerOfStatus::class)->discounted($this->order->refresh());

    expect(($this->message)())->toBeNull();

    App\Modules\Tenancy\Models\TenantSetting::query()->whereKey($this->tenant->id)->update(['order_notify_statuses' => json_encode(['discount'])]);
    app(NotifyCustomerOfStatus::class)->discounted($this->order->refresh());

    expect(($this->message)()?->body)->toContain('تم تطبيق خصم على طلبك');
});

test('D87 a line edit is told by default, with the new total', function (): void {
    app(NotifyCustomerOfStatus::class)->edited($this->order->refresh());

    expect(($this->message)()?->body)->toBe("أهلًا محمود، تم تعديل أصناف طلبك رقم {$this->order->number} من {$this->tenant->name}، والإجمالي الآن 540.00. التفاصيل في صفحة الطلب.");
});

test('D87 a line edit is not told when the store leaves edited out, nor to a customer who opted out', function (): void {
    App\Modules\Tenancy\Models\TenantSetting::query()->whereKey($this->tenant->id)->update(['order_notify_statuses' => json_encode(['confirmed'])]);
    app(NotifyCustomerOfStatus::class)->edited($this->order->refresh());

    App\Modules\Tenancy\Models\TenantSetting::query()->whereKey($this->tenant->id)->update(['order_notify_statuses' => json_encode(['edited'])]);
    $this->customer->forceFill(['order_notifications_opt_out' => true])->save();
    app(NotifyCustomerOfStatus::class)->edited($this->order->refresh());

    expect(($this->message)())->toBeNull();
});
