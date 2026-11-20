<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Collections\Actions\ApplyCustomerCredit;
use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Actions\VoidCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Customers\Actions\AddCartItem;
use App\Modules\Customers\Actions\ArchiveCustomerGroup;
use App\Modules\Customers\Actions\AssignCustomersToGroup;
use App\Modules\Customers\Actions\CreateCustomerByMerchant;
use App\Modules\Customers\Actions\CreateCustomerGroup;
use App\Modules\Customers\Actions\SetCustomerSkuPrice;
use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Data\CartLineData;
use App\Modules\Customers\Data\CustomerData;
use App\Modules\Customers\Data\CustomerGroupData;
use App\Modules\Customers\Data\GroupAssignmentData;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Expenses\Actions\RecordExpense;
use App\Modules\Expenses\Actions\VoidExpense;
use App\Modules\Expenses\Data\ExpenseData;
use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Actions\ReceiveStock;
use App\Modules\Inventory\Actions\SetStockCost;
use App\Modules\Inventory\Data\StockAdjustmentData;
use App\Modules\Inventory\Data\StockReceiptData;
use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Actions\ApplyOrderDiscount;
use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Actions\Checkout;
use App\Modules\Orders\Actions\EditOrderLines;
use App\Modules\Orders\Actions\MarkNotificationsRead;
use App\Modules\Orders\Actions\PlacePreorder;
use App\Modules\Orders\Actions\RecordReturn;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Actions\VoidReturn;
use App\Modules\Orders\Data\CancelData;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Data\DiscountData;
use App\Modules\Orders\Data\OrderEditData;
use App\Modules\Orders\Data\OrderEditLineData;
use App\Modules\Orders\Data\ReturnData;
use App\Modules\Orders\Data\ReturnLineData;
use App\Modules\Orders\Data\TransitionData;
use App\Modules\Orders\Enums\CancelledBy;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundMethod;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Pricing\Actions\AdvancePromotions;
use App\Modules\Pricing\Actions\CancelPromotion;
use App\Modules\Pricing\Actions\CreatePromotion;
use App\Modules\Pricing\Data\PromotionData;
use App\Modules\Pricing\Enums\DiscountType;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Tenancy\Actions\CreateTenantUser;
use App\Modules\Tenancy\Data\TenantUserData;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\TenantContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Demo activity for the `alnoor` store (or the one `tenant:demo` creates), on top of StorefrontDemoSeeder's catalog:
 * four staff users, a cost on every stocked SKU, 14 bookshops across Egypt as customers (two price groups, a few
 * negotiated prices) and about two months of wholesale orders placed, edited, discounted, confirmed, delivered,
 * cancelled, collected and partly returned through the module actions, with the clock moved to each step's time.
 * Around them the store lives: promotions start, end and get cancelled, stock is received and adjusted, expenses are
 * paid, credits from returns pay later orders and a few bookshops pre-order the Coming Soon book. The result feeds
 * every dashboard screen and report tab: delivered sales and profit in the last 30 days, orders in every status, stale
 * pending orders (D10), unpaid and partly paid deliveries (D59), returns kept as credit or refunded, two books low on
 * stock (D36) and only the last day's notifications unread. The same randomizer seed gives the same story on every
 * machine. Runs once: a store with orders is skipped.
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

    /** @var list<array{string, string, TenantUserRole}> name, email local part, role: one user per staff role */
    private const array STAFF = [
        ['أحمد سمير', 'manager', TenantUserRole::Manager],
        ['محمود عادل', 'sales', TenantUserRole::Sales],
        ['كريم فتحي', 'warehouse', TenantUserRole::Warehouse],
        ['منى حسن', 'accountant', TenantUserRole::Accountant],
    ];

    /** @var list<array{string, string, list<int>}> name, discount %, bookshops (by index); the last group is archived */
    private const array GROUPS = [
        ['كبار العملاء', '5.00', [0, 1, 2]],
        ['مكتبات الصعيد', '3.00', [8, 9, 10]],
        ['عملاء موسميين', '4.00', []],
    ];

    /** Unit cost as a % of the wholesale price per base unit. */
    private const int COST_PERCENT = 70;

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

    private const array RETURN_REASONS = [
        'نسخ بها عيب طباعة',
        'غلاف تالف أثناء الشحن',
        'العميل طلب كمية زيادة عن احتياجه',
        'طبعة قديمة اتبعتت بالخطأ',
    ];

    private Randomizer $random;

    /** @var array<string, int> user id by role value */
    private array $staff = [];

    /** @var list<array{CarbonImmutable, Closure(): void}> what happens in the store between orders, by time */
    private array $events = [];

    private int $inFlight = 0;

    private int $refunds = 0;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CreateTenantUser $createUser,
        private readonly CreateCustomerByMerchant $createCustomer,
        private readonly CreateCustomerGroup $createGroup,
        private readonly AssignCustomersToGroup $assignGroup,
        private readonly ArchiveCustomerGroup $archiveGroup,
        private readonly SetCustomerSkuPrice $customerPrice,
        private readonly SetStockCost $setCost,
        private readonly ReceiveStock $receive,
        private readonly AdjustStock $adjust,
        private readonly AddCartItem $addToCart,
        private readonly Checkout $checkout,
        private readonly PlacePreorder $preorder,
        private readonly TransitionOrder $transition,
        private readonly EditOrderLines $editLines,
        private readonly ApplyOrderDiscount $discount,
        private readonly CancelOrder $cancel,
        private readonly RecordCollection $collect,
        private readonly VoidCollection $voidCollection,
        private readonly RecordReturn $recordReturn,
        private readonly VoidReturn $voidReturn,
        private readonly ApplyCustomerCredit $applyCredit,
        private readonly CreatePromotion $createPromotion,
        private readonly CancelPromotion $cancelPromotion,
        private readonly AdvancePromotions $advancePromotions,
        private readonly RecordExpense $recordExpense,
        private readonly VoidExpense $voidExpense,
        private readonly MarkNotificationsRead $markRead,
    ) {}

    /**
     * @param  string|null  $staffPassword  the staff users' password; null (tenant:demo) = a random one nobody knows
     */
    public function run(string $subdomain = 'alnoor', ?string $staffPassword = null): void
    {
        $tenant = Tenant::query()->where('subdomain', $subdomain)->firstOrFail();
        $this->random = new Randomizer(new Mt19937(2026));
        $this->events = [];
        $this->inFlight = 0;
        $this->refunds = 0;
        $frozen = CarbonImmutable::getTestNow();
        $now = CarbonImmutable::now('Africa/Cairo');
        $today = $now->startOfDay();

        $this->context->runAs($tenant, function () use ($frozen, $now, $today, $subdomain, $staffPassword): void {
            if (Order::query()->exists()) {
                return;
            }

            // The demo customers are bookshops: they order the books (and the grade set), not the supplies or toys (D68).
            $skus = Sku::query()
                ->where('code', 'like', 'BK-%')
                ->whereHas('product', fn (Builder $query): Builder => $query->where('status', ProductStatus::Active))
                ->with(['defaultUnit', 'product'])
                ->orderBy('code')
                ->get();
            $orderable = array_values($skus->all());

            if ($orderable === []) {
                throw new RuntimeException('The demo activity needs the demo catalog: run StorefrontDemoSeeder first.');
            }

            $gradeSet = Sku::query()->where('code', 'SET-DEMO-1')->with('defaultUnit')->first();

            try {
                $start = $today->subDays(self::DAYS + 5);
                $this->travelTo($start);
                $owner = TenantUser::query()->orderBy('id')->first()?->id;
                $this->staff = $this->staffUsers($subdomain, $staffPassword ?? Str::password(24));

                if ($owner !== null) {
                    $this->staff[TenantUserRole::Owner->value] = $owner;
                }
                $this->costs();
                $customers = $this->customers($start);
                $this->pricing($customers, $skus->all());
                $this->storeEvents($today, $orderable, $customers);

                foreach ($this->schedule($now) as $index => [$placedAt, $stale]) {
                    $this->eventsUntil($placedAt);
                    $this->travelTo($placedAt);
                    // Every ordering bookshop has orders: the first ones go one to each, the rest at random.
                    $customer = $customers[$index < self::ORDERING ? $index : $this->random->getInt(0, self::ORDERING - 1)];
                    $order = $this->place($customer, $orderable, $gradeSet);
                    $this->progress($order, $placedAt, $now, $stale);
                }

                $this->eventsUntil($now);
                $this->travelTo($now->subHours(2));
                $this->settleCredits();
                $this->corrections();
                $this->upcomingPromotions($today, $skus->all());
                $this->travelTo($now->subMinutes(30));
                $this->schoolOrder($customers[1], $skus->whereIn('code', ['BK-008', 'BK-016'])->all());
                $this->advancePromotions->handle($now);
            } finally {
                Carbon::setTestNow($frozen);
                CarbonImmutable::setTestNow($frozen);
            }

            $this->lowStock($skus->whereIn('code', ['BK-008', 'BK-016'])->all());

            $this->markRead->handle(array_values(MerchantNotification::query()
                ->where('created_at', '<', now()->subDay())
                ->get()
                ->map(fn (MerchantNotification $notification): int => $notification->id)
                ->all()));
        });
    }

    /**
     * One user per staff role, `{role}@{subdomain}.test`.
     *
     * @return array<string, int> user id by role value
     */
    private function staffUsers(string $subdomain, string $password): array
    {
        $ids = [];

        foreach (self::STAFF as [$name, $local, $role]) {
            $ids[$role->value] = $this->createUser->handle(new TenantUserData($name, "{$local}@{$subdomain}.test", $password, $role))->id;
        }

        return $ids;
    }

    /** The demo user acting in this role; the manager stands in for an owner the store does not have. */
    private function actor(TenantUserRole $role): int
    {
        return $this->staff[$role->value] ?? $this->staff[TenantUserRole::Manager->value] ?? throw new RuntimeException("No demo user for {$role->value}.");
    }

    /**
     * D96 d: the stock that was there before costing gets its average cost by hand, 70% of the wholesale price per
     * base unit, so profit and margin (D98) have a cost to work with from the first sale.
     */
    private function costs(): void
    {
        $stocked = SkuInventory::query()->where('on_hand', '>', 0)->orderBy('sku_id')->pluck('sku_id')->all();

        foreach (Sku::query()->whereKey($stocked)->with('defaultUnit')->orderBy('id')->get() as $sku) {
            $cost = $this->costOf($sku);

            if ($cost !== null) {
                $this->setCost->handle($sku, $cost);
            }
        }
    }

    /**
     * 70% of the default unit's wholesale price per base unit, times `$markupPercent`, half-up to the piaster; null
     * when the SKU has no default unit.
     */
    private function costOf(Sku $sku, int $markupPercent = 100): ?string
    {
        $unit = $sku->defaultUnit;

        if (! $unit instanceof SkuUnit) {
            return null;
        }

        return Money::divide(Money::times($unit->base_price, self::COST_PERCENT * $markupPercent), 10_000 * $unit->conversion_factor);
    }

    /**
     * @return list<Customer>
     */
    private function customers(CarbonImmutable $joined): array
    {
        $this->travelTo($joined);
        $customers = [];

        foreach (self::BOOKSHOPS as $index => [$name, $phone, $governorate, $city, $line, $landmark]) {
            $customer = $this->createCustomer->handle(new CustomerData($name, $phone, [
                new AddressData('المحل', $governorate, $city, $line, $landmark, isDefault: true),
            ]), $this->actor(TenantUserRole::Sales));

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
     * BR-PR12, BR-PR14: two price groups with their bookshops, a third group no longer used (archived), and the
     * negotiated prices of two bookshops, all before the first order so every order is priced with them.
     *
     * @param  list<Customer>  $customers
     * @param  array<int, Sku>  $books
     */
    private function pricing(array $customers, array $books): void
    {
        foreach (self::GROUPS as $sort => [$name, $percent, $members]) {
            $group = $this->createGroup->handle(new CustomerGroupData($name, $percent, $sort + 1));

            if ($members === []) {
                $this->archiveGroup->handle($group);

                continue;
            }

            $this->assignGroup->handle($group, new GroupAssignmentData(array_map(fn (int $index): int => $customers[$index]->id, $members)));
        }

        $books = array_values($books);
        $negotiated = [
            [$customers[3], [$books[0], $books[1], $books[4]], 90, 'سعر متفق عليه لكميات الموسم'],
            [$customers[4], [$books[2], $books[6]], 92, 'عميل قديم، سعر خاص'],
        ];

        foreach ($negotiated as [$customer, $skus, $share, $note]) {
            foreach ($skus as $sku) {
                $unit = $sku->defaultUnit ?? throw new RuntimeException("SKU {$sku->code} has no default unit.");
                $this->customerPrice->handle($customer, $unit, Money::divide(Money::times($unit->base_price, $share), 100), $note);
            }
        }
    }

    /**
     * What happens between the orders, each at its own time: promotions created, cancelled; stock received at a newer
     * cost and adjusted; expenses paid (one recorded twice and voided); bookshops pre-ordering the Coming Soon book.
     *
     * @param  non-empty-list<Sku>  $books
     * @param  list<Customer>  $customers
     */
    private function storeEvents(CarbonImmutable $today, array $books, array $customers): void
    {
        $manager = $this->actor(TenantUserRole::Manager);
        $warehouse = $this->actor(TenantUserRole::Warehouse);

        // BR-PR15 (D90): one promotion over, one cancelled before it began, one running now.
        $firstGrade = array_values(array_filter($books, fn (Sku $sku): bool => str_contains($sku->product->name, 'أولى إعدادي')));
        $adwaa = array_values(array_filter($books, fn (Sku $sku): bool => str_starts_with($sku->product->name, 'الأضواء')));
        $this->at($today->subDays(50)->setTime(11, 0), fn (): Promotion => $this->promotion('عرض بداية الدراسة', '10.00', $today->subDays(48), $today->subDays(34), $firstGrade, $manager));
        $this->at($today->subDays(24)->setTime(11, 0), function () use ($today, $books, $manager): void {
            $promotion = $this->promotion('عرض نهاية الشهر', '5.00', $today->subDays(20), $today->subDays(16), array_slice($books, 10, 6), $manager);
            $this->travelTo($today->subDays(22)->setTime(16, 0));
            $this->cancelPromotion->handle($promotion);
        });
        $this->at($today->subDays(9)->setTime(11, 0), fn (): Promotion => $this->promotion('خصم كتب الأضواء', '7.00', $today->subDays(8), $today->addDays(12), $adwaa, $manager));

        // BR-INV: two deliveries from the printer at a newer cost, and three adjustments with a reason (D97).
        foreach ([[42, 0], [15, 6]] as [$daysAgo, $offset]) {
            $this->at($today->subDays($daysAgo)->setTime(10, 30), function () use ($books, $offset, $warehouse): void {
                foreach (array_slice($books, $offset, 6) as $sku) {
                    $cost = $this->costOf($sku, 105);

                    if ($cost !== null) {
                        $this->receive->handle($sku, new StockReceiptData(300, $cost, null, 'توريد من المطبعة'), $warehouse);
                    }
                }
            });
        }

        $adjustments = [
            [27, 2, -6, AdjustmentReason::Damaged, 'نسخ اتبلت من تسريب مياه في المخزن'],
            [18, 10, 4, AdjustmentReason::Found, 'كرتونة في المخزن الخلفي'],
            [9, 19, -3, AdjustmentReason::RecordingError, 'خطأ في عدد توريد سابق'],
        ];

        foreach ($adjustments as [$daysAgo, $index, $change, $reason, $note]) {
            $sku = $books[$index] ?? $books[0];
            $this->at($today->subDays($daysAgo)->setTime(17, 0), function () use ($sku, $change, $reason, $note, $warehouse): void {
                $onHand = SkuInventory::query()->where('sku_id', $sku->id)->sole()->on_hand;
                $this->adjust->handle($sku, new StockAdjustmentData($onHand + $change, $reason, $note), $warehouse);
            });
        }

        $this->expenses($today);
        $this->preorders($today, $customers);

        usort($this->events, fn (array $a, array $b): int => $a[0] <=> $b[0]);
    }

    /**
     * @param  list<Sku>  $skus
     */
    private function promotion(string $name, string $percent, CarbonImmutable $starts, CarbonImmutable $ends, array $skus, int $actor, PromotionStatus $status = PromotionStatus::Scheduled): Promotion
    {
        return $this->createPromotion->handle(new PromotionData(
            name: $name,
            discountPercent: $percent,
            startsAt: $starts,
            endsAt: $ends,
            skuIds: array_map(fn (Sku $sku): int => $sku->id, $skus),
            status: $status,
        ), $actor);
    }

    /**
     * The store's running costs (D100): rent on the 1st, utilities on the 5th, the bank fee on the 10th, salaries on
     * the 28th, deliveries to the provinces twice a week, and a few one-offs. One transport bill recorded twice is voided.
     */
    private function expenses(CarbonImmutable $today): void
    {
        $accountant = $this->actor(TenantUserRole::Accountant);
        $oneOffs = [
            50 => [ExpenseCategory::Marketing, '1500.00', 'إعلان ممول على فيسبوك'],
            35 => [ExpenseCategory::Maintenance, '400.00', 'صيانة التكييف'],
            20 => [ExpenseCategory::Packaging, '450.00', 'كراتين وشريط لاصق'],
            6 => [ExpenseCategory::Packaging, '380.00', 'كراتين وشريط لاصق'],
            13 => [ExpenseCategory::Other, '220.00', 'ضيافة'],
        ];

        for ($daysAgo = self::DAYS; $daysAgo >= 0; $daysAgo--) {
            $day = $today->subDays($daysAgo);
            $bills = [];

            match ($day->day) {
                1 => $bills[] = [ExpenseCategory::Rent, '6000.00', 'إيجار المخزن'],
                5 => $bills[] = [ExpenseCategory::Utilities, $this->random->getInt(75, 110) * 10 .'.00', 'كهرباء ومياه'],
                10 => $bills[] = [ExpenseCategory::Fees, '85.00', 'مصاريف الحساب البنكي'],
                28 => $bills[] = [ExpenseCategory::Salaries, '14500.00', 'مرتبات الشهر'],
                default => null,
            };

            if ($day->isSaturday() || $day->isTuesday()) {
                $bills[] = [ExpenseCategory::Transport, $this->random->getInt(25, 60) * 10 .'.00', 'نقل طلبيات للمحافظات'];
            }

            if (isset($oneOffs[$daysAgo])) {
                $bills[] = $oneOffs[$daysAgo];
            }

            foreach ($bills as $index => [$category, $amount, $note]) {
                $this->at($day->setTime(18, $index * 5), fn (): Expense => $this->recordExpense->handle(new ExpenseData($day->toDateString(), $amount, $category, $note), $accountant));
            }
        }

        $duplicate = $today->subDays(16);
        $this->at($duplicate->setTime(19, 0), function () use ($duplicate, $accountant): void {
            $expense = $this->recordExpense->handle(new ExpenseData($duplicate->toDateString(), '350.00', ExpenseCategory::Transport, 'نقل طلبيات للمحافظات'), $accountant);
            $this->travelTo($duplicate->setTime(19, 20));
            $this->voidExpense->handle($expense, 'اتسجلت مرتين', $accountant);
        });
    }

    /**
     * BR-O14 (D88): three bookshops pre-order the Coming Soon book in the last week; one cancels a day later.
     *
     * @param  list<Customer>  $customers
     */
    private function preorders(CarbonImmutable $today, array $customers): void
    {
        $book = Sku::query()
            ->whereHas('product', fn (Builder $query): Builder => $query->where('status', ProductStatus::ComingSoon)->where('preorder_enabled', true))
            ->first();

        if (! $book instanceof Sku) {
            return;
        }

        foreach ([[6, 5, 30, false], [4, 6, 50, true], [2, 7, 20, false]] as [$daysAgo, $index, $quantity, $cancelled]) {
            $customer = $customers[$index];
            $this->at($today->subDays($daysAgo)->setTime(12, 30), function () use ($customer, $book, $quantity, $cancelled, $daysAgo, $today): void {
                $this->addToCart->handle($customer, new CartLineData($book->id, $quantity, CartLineKind::Preorder));
                $address = CustomerAddress::query()->where('customer_id', $customer->id)->orderBy('id')->firstOrFail();
                $order = $this->preorder->handle($customer, new CheckoutData($address->id, 'أول ما الكتاب ينزل'));

                if ($cancelled) {
                    $this->travelTo($today->subDays($daysAgo - 1)->setTime(13, 0));
                    $this->cancel->handle($order, new CancelData('هنستنى الطبعة الجديدة'), CancelledBy::Customer);
                }
            });
        }
    }

    /** @param  Closure(): mixed  $event */
    private function at(CarbonImmutable $when, Closure $event): void
    {
        $this->events[] = [$when, function () use ($event): void {
            $event();
        }];
    }

    /** Runs, in time order, every store event due by `$at`. */
    private function eventsUntil(CarbonImmutable $at): void
    {
        while ($this->events !== [] && $this->events[0][0]->lessThanOrEqualTo($at)) {
            [$when, $event] = array_shift($this->events);
            $this->travelTo($when);
            $event();
        }
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

            // At least two a day in the last three days, so orders on their way reach every status.
            $count = $this->random->getInt($daysAgo >= 1 && $daysAgo <= 3 ? 2 : 0, $daysAgo < 20 ? 3 : 2);

            for ($i = 0; $i < $count; $i++) {
                $at = $day->setTime($this->random->getInt(9, 19), $this->random->getInt(0, 59));

                if ($at->lessThan($now)) {
                    $times[] = [$at, false];
                }
            }
        }

        $unconfirmed = $today->subDays(2)->setTime(10, 15);
        $times = [...$times, [$unconfirmed, true], [$unconfirmed->addMinutes(40), true]];
        usort($times, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $times;
    }

    /**
     * Two to five titles, in the quantities a bookshop orders: tens of copies, or a few cartons; one order in five
     * also takes some grade sets (D93).
     *
     * @param  non-empty-list<Sku>  $skus
     */
    private function place(Customer $customer, array $skus, ?Sku $gradeSet): Order
    {
        foreach ($this->random->pickArrayKeys($skus, $this->random->getInt(2, 5)) as $key) {
            $sku = $skus[$key];
            $unit = $sku->defaultUnit ?? throw new RuntimeException("SKU {$sku->code} has no default unit.");
            $quantity = $unit->conversion_factor > 1
                ? $this->random->getInt($unit->moq, $unit->moq + 3)
                : 5 * $this->random->getInt(2, 12);
            $this->addToCart->handle($customer, new CartLineData($sku->id, $quantity));
        }

        if ($gradeSet instanceof Sku && $this->random->getInt(1, 5) === 1) {
            $this->addToCart->handle($customer, new CartLineData($gradeSet->id, 5 * $this->random->getInt(1, 4)));
        }

        $address = CustomerAddress::query()->where('customer_id', $customer->id)->orderBy('id')->firstOrFail();
        $note = self::NOTES[$this->random->getInt(0, count(self::NOTES) - 1)];

        return $this->checkout->handle($customer, new CheckoutData($address->id, $note));
    }

    /**
     * Orders older than three days end delivered (one in eight cancelled); recent ones are still on their way. A confirmed order is
     * sometimes changed (BR-O12) or discounted (BR-PR08). A delivery is paid in full on the spot, half paid on
     * delivery, or left on account; some come partly back a few days later (BR-R01).
     */
    private function progress(Order $order, CarbonImmutable $placedAt, CarbonImmutable $now, bool $stale): void
    {
        $age = (int) $placedAt->startOfDay()->diffInDays($now->startOfDay());
        $owner = $this->actor(TenantUserRole::Owner);
        $warehouse = $this->actor(TenantUserRole::Warehouse);

        if ($stale || $age === 0) {
            return;
        }

        $cancelled = $this->random->getInt(1, 8) === 1;

        if ($cancelled && $age >= 4) {
            $this->travelTo($placedAt->addHours(3));
            $reason = self::CANCEL_REASONS[$this->random->getInt(0, count(self::CANCEL_REASONS) - 1)];
            $this->cancel->handle($order, new CancelData($reason), CancelledBy::TenantUser, $owner);

            return;
        }

        $steps = [
            [OrderStatus::Confirmed, $placedAt->addHours(2), $this->random->getInt(0, 1) === 0 ? $owner : $this->actor(TenantUserRole::Sales)],
            [OrderStatus::Preparing, $placedAt->addDay()->setTime(10, 0), $warehouse],
            [OrderStatus::OutForDelivery, $placedAt->addDays(2)->setTime(9, 30), $warehouse],
            [OrderStatus::Delivered, $placedAt->addDays(2)->setTime(14, 0), $warehouse],
        ];
        // The last three days' orders are still on their way: three days old out for delivery, two days old confirmed,
        // preparing or out for delivery in turn, yesterday's confirmed or preparing in turn.
        $last = match (true) {
            $age >= 4 => 3,
            $age === 3 => 2,
            default => $this->inFlight++ % ($age === 2 ? 3 : 2),
        };
        $payment = $this->random->getInt(1, 10);
        $change = $this->random->getInt(1, 12);

        foreach (array_slice($steps, 0, $last + 1) as [$to, $at, $actor]) {
            if ($at->greaterThanOrEqualTo($now)) {
                return;
            }

            $this->travelTo($at);
            $delivering = $to === OrderStatus::Delivered;
            $this->transition->handle($order, new TransitionData($to, collectFullAmount: $delivering && $payment <= 6), $actor);

            if ($to === OrderStatus::Confirmed) {
                $this->travelTo($at->addMinutes(30));
                $this->change($order->refresh(), $change);
            }
        }

        if ($last !== 3) {
            return;
        }

        if ($payment <= 8 && $payment > 6) {
            $paidAt = $steps[3][1];
            $this->travelTo($paidAt);
            $this->collect->handle($order, new CollectionData($this->half($order->refresh()->order_total), $paidAt), $this->actor(TenantUserRole::Accountant));
        }

        $returnedAt = $placedAt->addDays(5)->setTime(12, 0);

        if ($this->random->getInt(1, 4) === 1 && $returnedAt->lessThan($now)) {
            $this->travelTo($returnedAt);
            $this->returnPart($order->refresh(), $returnedAt);
        }
    }

    /**
     * BR-O12, BR-PR08: one confirmed order in six gets more copies of its first title, one in twelve a 3% discount.
     */
    private function change(Order $order, int $roll): void
    {
        $manager = $this->actor(TenantUserRole::Manager);

        if ($roll <= 2) {
            $lines = [];
            $bumped = false;

            foreach ($this->lines($order) as $item) {
                $more = 0;

                if (! $bumped && ! $item->is_bundle) {
                    $more = $item->conversion_factor > 1 ? 1 : 10;
                    $bumped = true;
                }

                $lines[] = new OrderEditLineData($item->id, $item->sku_unit_id ?? throw new RuntimeException("Order line {$item->id} has no SKU unit."), $item->quantity + $more);
            }

            $this->editLines->handle($order, new OrderEditData($lines, 'العميل طلب زيادة الكمية بالتليفون'), $manager);
        } elseif ($roll === 3) {
            $this->discount->handle($order, new DiscountData(type: DiscountType::Percent, value: '3.00', reason: 'خصم عميل دائم'), $manager, notifyCustomer: false);
        }
    }

    /**
     * BR-R01..R04: part of one title comes back; what was paid beyond the new total goes back in cash or stays as the
     * bookshop's credit (BR-C07).
     */
    private function returnPart(Order $order, CarbonImmutable $at): void
    {
        $item = $this->lines($order)->first(fn (OrderItem $item): bool => ! $item->is_bundle);

        if (! $item instanceof OrderItem) {
            return;
        }

        $out = $item->delivered_quantity ?? $item->quantity;
        $quantity = min($out, $item->conversion_factor > 1 ? 1 : 5);

        if ($quantity < 1) {
            return;
        }

        $this->recordReturn->handle($order, new ReturnData(
            reason: self::RETURN_REASONS[$this->random->getInt(0, count(self::RETURN_REASONS) - 1)],
            lines: [new ReturnLineData($item->id, $quantity)],
            returnedAt: $at,
            refund: true,
            // Paid orders give money back: kept as credit and refunded in cash in turn.
            refundTo: Money::compare($order->collected_amount, '0.00') > 0 && $this->refunds++ % 2 === 0 ? RefundMethod::Credit : RefundMethod::Cash,
        ), $this->actor(TenantUserRole::Manager));
    }

    /**
     * The order's own live lines (a bundle as its bundle line, its components left out), oldest first.
     *
     * @return Collection<int, OrderItem>
     */
    private function lines(Order $order): Collection
    {
        return OrderItem::query()
            ->where('order_id', $order->id)
            ->whereNull('removed_at')
            ->whereNull('parent_item_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * BR-C07: every credit but the newest pays what the same bookshop still owes on a delivered order; the newest
     * stays on the bookshop's account.
     */
    private function settleCredits(): void
    {
        $credits = CustomerCredit::query()->whereNull('voided_at')->where('remaining', '>', 0)->orderBy('id')->get();
        $accountant = $this->actor(TenantUserRole::Accountant);

        foreach ($credits->slice(0, max(0, $credits->count() - 1)) as $credit) {
            $order = Order::query()
                ->where('customer_id', $credit->customer_id)
                ->where('kind', OrderKind::Order)
                ->where('status', OrderStatus::Delivered)
                ->whereIn('collection_status', [CollectionStatus::Unpaid, CollectionStatus::PartiallyCollected])
                ->orderByDesc('placed_at')
                ->first();

            if (! $order instanceof Order) {
                continue;
            }

            $owed = Money::subtract($order->netTotal(), $order->collected_amount);
            $amount = Money::compare($owed, $credit->remaining) < 0 ? $owed : $credit->remaining;

            if (Money::compare($amount, '0.00') > 0) {
                $this->applyCredit->handle($order, $credit, $amount, $accountant);
            }
        }
    }

    /**
     * Mistakes put right (BR-R06, BR-C02): a return recorded on the wrong order is voided, and a payment recorded
     * with the wrong amount is voided and recorded again.
     */
    private function corrections(): void
    {
        $manager = $this->actor(TenantUserRole::Manager);
        $accountant = $this->actor(TenantUserRole::Accountant);
        $return = OrderReturn::query()->whereNull('voided_at')->whereNull('refund_method')->orderBy('id')->first();

        if ($return instanceof OrderReturn) {
            $this->voidReturn->handle($return, 'المرتجع اتسجل على طلب غلط', $manager);
        }

        $payment = CashCollection::query()
            ->where('kind', CollectionKind::Payment)
            ->where('method', CollectionMethod::Cash)
            ->whereNull('voided_at')
            ->whereHas('order', fn (Builder $query): Builder => $query->where('collection_status', CollectionStatus::PartiallyCollected))
            ->orderBy('id')
            ->first();

        if ($payment instanceof CashCollection) {
            $this->voidCollection->handle($payment, 'المبلغ اتكتب غلط', $accountant);
            $this->collect->handle($payment->order->refresh(), new CollectionData($payment->amount, $payment->collected_at, 'تصحيح المبلغ'), $accountant);
        }
    }

    /**
     * BR-PR15: a promotion scheduled for the second term and a draft still being prepared.
     *
     * @param  array<int, Sku>  $books
     */
    private function upcomingPromotions(CarbonImmutable $today, array $books): void
    {
        $manager = $this->actor(TenantUserRole::Manager);
        $books = array_values($books);
        $this->promotion('عرض الترم الثاني', '12.00', $today->addDays(25), $today->addDays(40), array_slice($books, 0, 15), $manager);
        $toys = Sku::query()->where('code', 'like', 'TY-%')->orderBy('code')->get()->all();
        $this->promotion('تصفية ألعاب', '15.00', $today->addDays(5), $today->addDays(15), $toys === [] ? array_slice($books, 15, 5) : array_values($toys), $manager, PromotionStatus::Draft);
    }

    /** Half the total, rounded down to 50 pounds, as a bookshop pays a first instalment. */
    private function half(string $total): string
    {
        return bcmul(bcdiv($total, '100', 0), '50', 2);
    }

    /**
     * A school's order placed through a bookshop just now, still pending: every whole carton of the two titles but the
     * last 25 copies, so they run low by selling (D36) and the stocktake after it only corrects a few copies.
     *
     * @param  array<int, Sku>  $skus
     */
    private function schoolOrder(Customer $customer, array $skus): void
    {
        $lines = 0;

        foreach ($skus as $sku) {
            $unit = $sku->defaultUnit ?? throw new RuntimeException("SKU {$sku->code} has no default unit.");
            $cartons = intdiv(SkuInventory::query()->where('sku_id', $sku->id)->sole()->available - 25, $unit->conversion_factor);

            if ($cartons >= $unit->moq) {
                $this->addToCart->handle($customer, new CartLineData($sku->id, $cartons));
                $lines++;
            }
        }

        if ($lines > 0) {
            $address = CustomerAddress::query()->where('customer_id', $customer->id)->orderBy('id')->firstOrFail();
            $this->checkout->handle($customer, new CheckoutData($address->id, 'طلبية مدرسة للترم الثاني'));
        }
    }

    /**
     * Two carton titles nearly sold out: available stock under the low-stock threshold of MOQ × carton (D36).
     *
     * @param  array<int, Sku>  $skus
     */
    private function lowStock(array $skus): void
    {
        foreach ($skus as $sku) {
            $inventory = SkuInventory::query()->where('sku_id', $sku->id)->sole();
            $this->adjust->handle($sku, new StockAdjustmentData($inventory->reserved + 25, AdjustmentReason::Stocktake, 'آخر الشهر'), $this->actor(TenantUserRole::Warehouse));
        }
    }

    private function travelTo(CarbonImmutable $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }
}
