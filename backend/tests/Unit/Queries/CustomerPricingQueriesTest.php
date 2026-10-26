<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Models\CustomerSkuPrice;
use App\Modules\Customers\Queries\CustomerGroupsQuery;
use App\Modules\Customers\Queries\CustomerListQuery;
use App\Modules\Customers\Queries\CustomerSkuPricesQuery;
use App\Modules\Customers\Queries\PricingContextQuery;
use App\Modules\Pricing\Contracts\CustomerPricing;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

function pricingQuerySku(string $name = 'كتاب', string $code = 'SKU-1'): Sku
{
    return Product::factory()->withSku(['code' => $code])->create(['name' => $name])->sku()->sole();
}

test('BR-PR14 a guest has no pricing context', function (): void {
    expect(app(PricingContextQuery::class)->for(null))->toBeNull()
        ->and(app(PricingContextQuery::class)->for($this->makeOwner()))->toBeNull();
});

test('BR-PR14 a customer without a group or prices gets an empty context', function (): void {
    expect(app(PricingContextQuery::class)->for($this->makeCustomer()))->toEqual(new PricingContext(null, []));
});

test('BR-PR14 the context holds the active group % and the negotiated prices, read in one query', function (): void {
    $group = CustomerGroup::factory()->create(['discount_percent' => '12.50']);
    $customer = $this->makeCustomer(['customer_group_id' => $group->id]);
    [$a, $b] = [pricingQuerySku('أ', 'A-1'), pricingQuerySku('ب', 'B-1')];
    CustomerSkuPrice::factory()->create(['customer_id' => $customer->id, 'sku_id' => $a->id, 'unit_price' => '70.00']);
    CustomerSkuPrice::factory()->create(['customer_id' => $customer->id, 'sku_id' => $b->id, 'unit_price' => '15.25']);
    CustomerSkuPrice::factory()->create(['sku_id' => $a->id, 'unit_price' => '1.00']);
    DB::enableQueryLog();

    $context = app(PricingContextQuery::class)->for($customer);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($context?->groupDiscountPercent)->toBe('12.50')
        ->and($context?->customerPrices)->toEqual([$a->id => '70.00', $b->id => '15.25']);
});

test('D89 an archived group no longer applies, the customer keeps it', function (): void {
    $group = CustomerGroup::factory()->archived()->create(['discount_percent' => '20.00']);
    $customer = $this->makeCustomer(['customer_group_id' => $group->id]);

    expect(app(PricingContextQuery::class)->for($customer)?->groupDiscountPercent)->toBeNull()
        ->and($customer->refresh()->customer_group_id)->toBe($group->id);
});

test('BR-T04 the context never reads another tenant\'s prices', function (): void {
    $customer = $this->makeCustomer();
    $other = $this->otherTenant();
    $this->asTenant($other, fn () => CustomerSkuPrice::factory()->create(['customer_id' => $customer->id, 'sku_id' => Product::factory()->withSku()->create()->sku()->sole()->id]));
    app(TenantContext::class)->set($this->tenant);

    expect(app(PricingContextQuery::class)->for($customer)?->customerPrices)->toBe([]);
});

test('BR-PR15 a guest gets a context only when a sku priced is on a live promotion', function (): void {
    [$on, $off] = [pricingQuerySku('أ', 'A-1'), pricingQuerySku('ب', 'B-1')];
    $promotion = Promotion::factory()->covering($on)->create(['discount_percent' => '20.00']);

    $context = app(PricingContextQuery::class)->for(null, [$on->id, $off->id]);

    expect(array_keys($context?->promotions ?? []))->toBe([$on->id])
        ->and($context?->promotion($on->id)?->promotionId)->toBe($promotion->id)
        ->and($context?->groupDiscountPercent)->toBeNull()
        ->and(app(PricingContextQuery::class)->for(null, [$off->id]))->toBeNull();
});

test('BR-PR15 a customer context carries the promotions of the skus priced, in one more query', function (): void {
    $group = CustomerGroup::factory()->create(['discount_percent' => '10.00']);
    $customer = $this->makeCustomer(['customer_group_id' => $group->id]);
    $sku = pricingQuerySku();
    Promotion::factory()->covering($sku)->create();
    DB::enableQueryLog();

    $context = app(PricingContextQuery::class)->for($customer, [$sku->id]);

    expect(DB::getQueryLog())->toHaveCount(2)
        ->and($context?->groupDiscountPercent)->toBe('10.00')
        ->and($context?->promotion($sku->id))->not->toBeNull();
});

test('BR-PR06 D90 the context reads the promotions live at the moment given', function (): void {
    $sku = pricingQuerySku();
    Promotion::factory()->covering($sku)->create(['starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-02 00:00:00']);

    expect(app(PricingContextQuery::class)->for(null, [$sku->id], CarbonImmutable::parse('2026-11-01 12:00:00')))->not->toBeNull()
        ->and(app(PricingContextQuery::class)->for(null, [$sku->id], CarbonImmutable::parse('2026-11-02 00:00:00')))->toBeNull();
});

test('BR-PR14 the query fills the storefront\'s customer pricing contract', function (): void {
    expect(app(CustomerPricing::class))->toBeInstanceOf(PricingContextQuery::class);
});

test('BR-PR12 the groups list is active first, then by sort order and name, with customer counts', function (): void {
    $archived = CustomerGroup::factory()->archived()->create(['name' => 'أ قديم']);
    $second = CustomerGroup::factory()->create(['name' => 'موزع', 'sort_order' => 2]);
    $first = CustomerGroup::factory()->create(['name' => 'مكتبة كبيرة', 'sort_order' => 1]);
    Customer::factory()->count(2)->create(['customer_group_id' => $first->id]);
    Customer::factory()->blocked()->create(['customer_group_id' => $first->id]);

    $groups = app(CustomerGroupsQuery::class)->get();

    expect($groups->modelKeys())->toBe([$first->id, $second->id, $archived->id])
        ->and($groups->first()?->customers_count)->toBe(3)
        ->and(app(CustomerGroupsQuery::class)->withCount($second)->customers_count)->toBe(0);
});

test('BR-PR13 a customer\'s prices are newest first with their sku, filtered by product or code', function (): void {
    $customer = $this->makeCustomer();
    $old = CustomerSkuPrice::factory()->create(['customer_id' => $customer->id, 'sku_id' => pricingQuerySku('الأضواء علوم', 'ADW-1')->id]);
    $this->travel(1)->minutes();
    $new = CustomerSkuPrice::factory()->create(['customer_id' => $customer->id, 'sku_id' => pricingQuerySku('سلاح التلميذ', 'SLH-1')->id]);
    CustomerSkuPrice::factory()->create();

    $all = app(CustomerSkuPricesQuery::class)->paginate($customer, null, 24);

    expect(collect($all->items())->pluck('id')->all())->toBe([$new->id, $old->id])
        ->and($all->items()[0]->relationLoaded('sku'))->toBeTrue()
        ->and(collect(app(CustomerSkuPricesQuery::class)->paginate($customer, 'الأضواء', 24)->items())->pluck('id')->all())->toBe([$old->id])
        ->and(collect(app(CustomerSkuPricesQuery::class)->paginate($customer, 'slh', 24)->items())->pluck('id')->all())->toBe([$new->id]);
});

test('BR-PR12 the customer list filters by group and loads it', function (): void {
    $group = CustomerGroup::factory()->create();
    $member = Customer::factory()->create(['customer_group_id' => $group->id]);
    Customer::factory()->create();

    $page = app(CustomerListQuery::class)->paginate(null, null, 24, $group->id);

    expect(collect($page->items())->pluck('id')->all())->toBe([$member->id])
        ->and($page->items()[0]->relationLoaded('group'))->toBeTrue()
        ->and(app(CustomerListQuery::class)->builder(null, CustomerStatus::Active)->count())->toBe(2);
});
