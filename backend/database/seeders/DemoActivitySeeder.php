<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Customers\Actions\AddCartItem;
use App\Modules\Customers\Actions\CreateCustomerByMerchant;
use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Data\CartLineData;
use App\Modules\Customers\Data\CustomerData;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Data\StockAdjustmentData;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Actions\Checkout;
use App\Modules\Orders\Actions\MarkNotificationsRead;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Data\CancelData;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Demo activity for the `alnoor` store, on top of StorefrontDemoSeeder's catalog: 14 bookshops across Egypt as
 * customers and about two months of wholesale orders placed, confirmed, delivered, cancelled and collected
 * through the module actions, with the clock moved to each step's time. The result feeds every dashboard
 * screen: delivered sales in the last 30 days, orders in every status, stale pending orders (D10), unpaid and
 * partly paid deliveries (D59), two books low on stock (D36) and only the last day's notifications unread.
 * The same randomizer seed gives the same story on every machine. Runs once: a store with orders is skipped.
 */
final class DemoActivitySeeder extends Seeder
{
    /** Days of history; the sales chart shows the last 30. */
    private const int DAYS = 60;

    /** @var list<array{string, string, string, string, string, string}> name, phone, governorate, city, address line, landmark */
    private const array BOOKSHOPS = [
        ['مكتبة الأمل', '+201001110001', 'القاهرة', 'شبرا', '45 شارع شبرا الرئيسي', 'أمام مدرسة التوفيقية'],
        ['مكتبة النجاح', '+201001110002', 'الجيزة', 'فيصل', '18 شارع العشرين', 'بجوار مسجد الرحمن'],
        ['مكتبة الهدى', '+201001110003', 'الإسكندرية', 'سيدي بشر', '7 شارع خالد بن الوليد', 'خلف موقف الترام'],
        ['مكتبة الفاروق', '+201001110004', 'الغربية', 'طنطا', '22 شارع البحر', 'أمام مدرسة طنطا الإعدادية'],
        ['مكتبة السلام', '+201001110005', 'الدقهلية', 'المنصورة', '9 شارع الجمهورية', 'بجوار بنك مصر'],
        ['مكتبة الرحمة', '+201001110006', 'الشرقية', 'الزقازيق', '31 شارع فاروق', 'أمام موقف القومية'],
        ['مكتبة المستقبل', '+201001110007', 'القليوبية', 'بنها', '14 شارع فريد ندا', 'بجوار السنترال'],
        ['مكتبة الإيمان', '+201001110008', 'المنوفية', 'شبين الكوم', '5 شارع جمال عبد الناصر', 'أمام الجامعة'],
        ['مكتبة النيل', '+201001110009', 'أسيوط', 'أسيوط', '27 شارع الجمهورية', 'بجوار مدرسة السلام'],
        ['مكتبة طيبة', '+201001110010', 'سوهاج', 'سوهاج', '11 شارع أحمد عرابي', 'أمام مجمع المدارس'],
        ['مكتبة البيان', '+201001110011', 'المنيا', 'المنيا', '40 شارع الحسيني', 'بجوار كوبري المنيا'],
        ['مكتبة الزهراء', '+201001110012', 'دمياط', 'دمياط الجديدة', '3 المنطقة المركزية', 'بجوار مكتب البريد'],
        ['مكتبة أولادنا', '+201001110013', 'الإسماعيلية', 'الإسماعيلية', '16 شارع الثلاثيني', 'أمام نادي الدراويش'],
        ['مكتبة الشمس', '+201001110014', 'بني سويف', 'بني سويف', '8 شارع عبد السلام عارف', 'بجوار مدرسة الشهيد'],
    ];

    /** The last two bookshops have not ordered yet: one invited, one waiting for approval. */
    private const int ORDERING = 12;

    private const array NOTES = [
        null,
        null,
        null,
        'التوصيل قبل الساعة 2 الظهر لو سمحت',
        'يرجى الاتصال قبل الوصول',
        'المحل مقفول يوم الجمعة',
        'محتاجين الكتب قبل بداية الدراسة',
    ];

    private const array CANCEL_REASONS = [
        'العميل طلب الإلغاء لتأخر ميعاد التوصيل',
        'تكرار الطلب بالخطأ',
        'العميل غيّر الكميات وسيطلب من جديد',
    ];

    private Randomizer $random;

    private ?int $actorId = null;

    public function run(
        TenantContext $context,
        CreateCustomerByMerchant $createCustomer,
        AddCartItem $addToCart,
        Checkout $checkout,
        TransitionOrder $transition,
        CancelOrder $cancel,
        RecordCollection $collect,
        AdjustStock $adjust,
        MarkNotificationsRead $markRead,
    ): void {
        $tenant = Tenant::query()->where('subdomain', 'alnoor')->firstOrFail();
        $this->random = new Randomizer(new Mt19937(2026));
        $frozen = CarbonImmutable::getTestNow();
        $now = CarbonImmutable::now('Africa/Cairo');
        $today = $now->startOfDay();

        $context->runAs($tenant, function () use ($frozen, $now, $today, $createCustomer, $addToCart, $checkout, $transition, $cancel, $collect, $adjust, $markRead): void {
            if (Order::query()->exists()) {
                return;
            }

            $this->actorId = TenantUser::query()->orderBy('id')->first()?->id;
            $skus = Sku::query()
                ->whereHas('product', fn (Builder $query): Builder => $query->where('status', ProductStatus::Active))
                ->orderBy('code')
                ->get(['id', 'code', 'moq', 'conversion_factor']);
            $orderable = array_values($skus->all());

            if ($orderable === []) {
                throw new RuntimeException('The demo activity needs the demo catalog: run StorefrontDemoSeeder first.');
            }

            try {
                $customers = $this->customers($today->subDays(self::DAYS + 5), $createCustomer);

                foreach ($this->schedule($now) as [$placedAt, $stale]) {
                    $this->travelTo($placedAt);
                    $customer = $customers[$this->random->getInt(0, self::ORDERING - 1)];
                    $order = $this->place($customer, $orderable, $addToCart, $checkout);
                    $this->progress($order, $placedAt, $now, $stale, $transition, $cancel, $collect);
                }

            } finally {
                Carbon::setTestNow($frozen);
                CarbonImmutable::setTestNow($frozen);
            }

            $this->lowStock($skus->whereIn('code', ['BK-008', 'BK-016'])->all(), $adjust);

            $markRead->handle(array_values(MerchantNotification::query()
                ->where('created_at', '<', now()->subDay())
                ->get()
                ->map(fn (MerchantNotification $notification): int => $notification->id)
                ->all()));
        });
    }

    /**
     * @return list<Customer>
     */
    private function customers(CarbonImmutable $joined, CreateCustomerByMerchant $create): array
    {
        $this->travelTo($joined);
        $customers = [];

        foreach (self::BOOKSHOPS as $index => [$name, $phone, $governorate, $city, $line, $landmark]) {
            $customer = $create->handle(new CustomerData($name, $phone, [
                new AddressData('المحل', $governorate, $city, $line, $landmark, isDefault: true),
            ]), $this->actorId);

            // The bookshop accepted the invitation and set a password, as ActivateInvitation would.
            $status = match (true) {
                $index < self::ORDERING => CustomerStatus::Active,
                $index === self::ORDERING => CustomerStatus::Invited,
                default => CustomerStatus::PendingApproval,
            };

            if ($status !== CustomerStatus::Invited) {
                $customer->forceFill(['status' => $status, 'password' => 'password', 'phone_verified_at' => now()])->save();
            }

            $customers[] = $customer;
        }

        return $customers;
    }

    /**
     * When each order is placed: busier on school days and in the last weeks, none on Fridays, plus two pending
     * orders from two days ago that nobody confirmed (stale, D10).
     *
     * @return list<array{CarbonImmutable, bool}>
     */
    private function schedule(CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $times = [];

        for ($daysAgo = self::DAYS; $daysAgo >= 0; $daysAgo--) {
            $day = $today->subDays($daysAgo);

            if ($day->isFriday()) {
                continue;
            }

            $count = $this->random->getInt(0, $daysAgo < 20 ? 3 : 2);

            for ($i = 0; $i < $count; $i++) {
                $at = $day->setTime($this->random->getInt(9, 19), $this->random->getInt(0, 59));

                if ($at->lessThan($now)) {
                    $times[] = [$at, false];
                }
            }
        }

        $unconfirmed = $today->subDays(2)->setTime(10, 15);

        return [...$times, [$unconfirmed, true], [$unconfirmed->addMinutes(40), true]];
    }

    /**
     * Two to five titles, in the quantities a bookshop orders: tens of copies, or a few cartons.
     *
     * @param  non-empty-list<Sku>  $skus
     */
    private function place(Customer $customer, array $skus, AddCartItem $addToCart, Checkout $checkout): Order
    {
        foreach ($this->random->pickArrayKeys($skus, $this->random->getInt(2, 5)) as $key) {
            $sku = $skus[$key];
            $quantity = $sku->conversion_factor > 1
                ? $this->random->getInt($sku->moq, $sku->moq + 3)
                : 5 * $this->random->getInt(2, 12);
            $addToCart->handle($customer, new CartLineData($sku->id, $quantity));
        }

        $address = CustomerAddress::query()->where('customer_id', $customer->id)->orderBy('id')->firstOrFail();
        $note = self::NOTES[$this->random->getInt(0, count(self::NOTES) - 1)];

        return $checkout->handle($customer, new CheckoutData($address->id, $note));
    }

    /**
     * Older orders end delivered (one in eight cancelled); recent ones are still on their way. A delivery is
     * paid in full on the spot, half paid on delivery, or left on account.
     */
    private function progress(Order $order, CarbonImmutable $placedAt, CarbonImmutable $now, bool $stale, TransitionOrder $transition, CancelOrder $cancel, RecordCollection $collect): void
    {
        $age = (int) $placedAt->startOfDay()->diffInDays($now->startOfDay());

        if ($stale || $age === 0) {
            return;
        }

        if ($this->random->getInt(1, 8) === 1) {
            $this->travelTo($placedAt->addHours(3));
            $reason = self::CANCEL_REASONS[$this->random->getInt(0, count(self::CANCEL_REASONS) - 1)];
            $cancel->handle($order, new CancelData($reason), CancelledBy::TenantUser, $this->actorId);

            return;
        }

        $steps = [
            [OrderStatus::Confirmed, $placedAt->addHours(2)],
            [OrderStatus::Preparing, $placedAt->addDay()->setTime(10, 0)],
            [OrderStatus::OutForDelivery, $placedAt->addDays(2)->setTime(9, 30)],
            [OrderStatus::Delivered, $placedAt->addDays(2)->setTime(14, 0)],
        ];
        $last = $age >= 3 ? 3 : $this->random->getInt(0, 2);
        $payment = $this->random->getInt(1, 10);

        foreach (array_slice($steps, 0, $last + 1) as [$to, $at]) {
            if ($at->greaterThanOrEqualTo($now)) {
                return;
            }

            $this->travelTo($at);
            $delivering = $to === OrderStatus::Delivered;
            $transition->handle($order, new TransitionData($to, collectFullAmount: $delivering && $payment <= 6), $this->actorId);
        }

        if ($last === 3 && $payment <= 8 && $payment > 6) {
            $paidAt = $steps[3][1];
            $this->travelTo($paidAt);
            $collect->handle($order, new CollectionData($this->half($order->refresh()->order_total), $paidAt), $this->actorId);
        }
    }

    /** Half the total, rounded down to 50 pounds, as a bookshop pays a first instalment. */
    private function half(string $total): string
    {
        return bcmul(bcdiv($total, '100', 0), '50', 2);
    }

    /**
     * Two carton titles nearly sold out: available stock under the low-stock threshold of MOQ × carton (D36).
     *
     * @param  array<int, Sku>  $skus
     */
    private function lowStock(array $skus, AdjustStock $adjust): void
    {
        foreach ($skus as $sku) {
            $inventory = SkuInventory::query()->where('sku_id', $sku->id)->sole();
            $adjust->handle($sku, new StockAdjustmentData($inventory->reserved + 25, 'جرد آخر الشهر'), $this->actorId);
        }
    }

    private function travelTo(CarbonImmutable $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }
}
