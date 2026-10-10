<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Customers\Enums\CustomerStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

/*
| The dashboard part of the OpenAPI spec (tasks/10) checked against real responses: each object has every
| required key of its schema and nothing undocumented, so the generated frontend types cannot drift.
*/

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
});

test('settings match the DashboardSettings schema', function (): void {
    $data = $this->tenantJson('GET', '/api/dashboard/settings')->assertOk()->json('data');

    expectDocumentedShape($data, 'DashboardSettings');
});

test('the category tree matches DashboardCategory, children included', function (): void {
    $this->inTenant(fn () => Category::factory()->childOf(Category::factory()->create())->create());

    $data = $this->tenantJson('GET', '/api/dashboard/categories')->assertOk()->json('data');

    expectDocumentedShape($data[0], 'DashboardCategory');
    expectDocumentedShape($data[0]['children'][0], 'DashboardCategory');
});

test('units, fields and options match their schemas', function (): void {
    $this->inTenant(function (): void {
        $grade = ProductFieldDefinition::factory()->select()->create(['key' => 'grade']);
        ProductFieldOption::factory()->for($grade, 'definition')->create();
    });
    $this->catalogBasics();

    expectDocumentedShape($this->tenantJson('GET', '/api/dashboard/units')->assertOk()->json('data.0'), 'Unit');

    $field = $this->tenantJson('GET', '/api/dashboard/fields')->assertOk()->json('data.0');

    expectDocumentedShape($field, 'FieldDefinition');
    expectDocumentedShape($field['options'][0], 'FieldOption');
});

test('a created product matches DashboardProduct with its SKU, tiers, stock, images and relations', function (): void {
    $payload = $this->productPayload(['opening_stock' => 100, 'sku' => ['tiers' => [['min_quantity' => 10, 'unit_price' => '85.00']]]]);
    $id = $this->tenantJson('POST', '/api/dashboard/products', $payload)->assertCreated()->json('data.id');
    $other = $this->inTenant(fn () => Product::factory()->withSku()->create());
    $this->tenantJson('POST', "/api/dashboard/products/{$id}/images", ['file' => UploadedFile::fake()->image('cover.jpg')])->assertCreated();
    $this->tenantJson('POST', "/api/dashboard/products/{$id}/relations", ['related_product_id' => $other->id, 'type' => 'companion'])->assertCreated();

    $data = $this->tenantJson('GET', "/api/dashboard/products/{$id}")->assertOk()->json('data');

    expectDocumentedShape($data, 'DashboardProduct');
    expectDocumentedShape($data['category'], 'IdName');
    expectDocumentedShape($data['sku'], 'DashboardSku');
    expectDocumentedShape($data['sku']['selling_unit'], 'IdName');
    expectDocumentedShape($data['sku']['tiers'][0], 'DashboardTier');
    expectDocumentedShape($data['sku']['inventory'], 'StockLevels');
    expectDocumentedShape($data['images'][0], 'ProductImage');
    expectDocumentedShape($data['relations'][0], 'ProductRelation');
});

test('the product list matches DashboardProduct', function (): void {
    $this->orderableSku();

    $response = $this->tenantJson('GET', '/api/dashboard/products')->assertOk();

    expectDocumentedShape($response->json('data.0'), 'DashboardProduct');
    expectDocumentedShape($response->json('data.0.sku'), 'DashboardSku');
    expectDocumentedShape($response->json('meta'), 'PaginationMeta');
});

test('pricing and the bulk results match their schemas', function (): void {
    $sku = $this->orderableSku(['cover_price' => '120.00']);
    $this->orderableSku(['code' => 'NO-COVER', 'cover_price' => null], name: 'بدون غلاف');
    $filter = ['filter' => ['q' => 'الأضواء'], 'base_price_percent' => '25', 'tiers' => [['min_quantity' => 10, 'discount_percent' => '30']]];

    $pricing = $this->tenantJson('PUT', "/api/dashboard/products/{$sku->product_id}/pricing", ['base_price' => '90.00', 'moq' => 5, 'tiers' => []])->assertOk()->json('data');
    $preview = $this->tenantJson('POST', '/api/dashboard/pricing/bulk/preview', [...$filter, 'filter' => ['status' => 'active']])->assertOk()->json('data');
    $applied = $this->tenantJson('POST', '/api/dashboard/pricing/bulk', $filter)->assertOk()->json('data');
    $status = $this->tenantJson('POST', '/api/dashboard/products/bulk/status', ['filter' => ['q' => 'الأضواء'], 'status' => 'inactive'])->assertOk()->json('data');

    expectDocumentedShape($pricing, 'SkuPricing');
    expectDocumentedShape($preview, 'BulkPricingResult');
    expectDocumentedShape($preview['skipped_no_cover_price'][0], 'SkippedNoCoverPrice');
    expectDocumentedShape($applied, 'BulkPricingResult');
    expectDocumentedShape($status, 'BulkStatusResult');
});

test('inventory rows, movements, bulk results and the below-reserved details match their schemas', function (): void {
    $sku = $this->orderableSku();
    $this->placeOrder($this->makeCustomer(), [$sku->id => 5]);

    $row = $this->tenantJson('GET', "/api/dashboard/inventory/{$sku->id}")->assertOk()->json('data');
    $list = $this->tenantJson('GET', '/api/dashboard/inventory')->assertOk()->json('data.0');
    $below = $this->tenantJson('POST', "/api/dashboard/inventory/{$sku->id}/adjust", ['new_on_hand' => 1, 'reason' => 'جرد'])->assertUnprocessable()->json('error.details');
    $bulk = $this->tenantJson('POST', '/api/dashboard/inventory/bulk-adjust', [
        ['sku_id' => $sku->id, 'new_on_hand' => 1, 'reason' => 'جرد'],
    ])->assertOk()->json('data');
    $this->tenantJson('POST', "/api/dashboard/inventory/{$sku->id}/adjust", ['new_on_hand' => 500, 'reason' => 'جرد'])->assertOk();
    $movement = $this->tenantJson('GET', "/api/dashboard/inventory/{$sku->id}/movements")->assertOk()->json('data.0');

    expectDocumentedShape($row, 'InventoryRow');
    expectDocumentedShape($list, 'InventoryRow');
    expectDocumentedShape($below, 'BelowReservedDetails');
    expectDocumentedShape($bulk, 'BulkAdjustResult');
    expectDocumentedShape($bulk['skipped'][0], 'BulkAdjustSkipped');
    expectDocumentedShape($movement, 'StockMovement');
    expectDocumentedShape($movement['actor'], 'Actor');
});

test('customers match DashboardCustomer in the list, on create and in the detail with recent orders', function (): void {
    Queue::fake();
    $customer = $this->makeCustomer();
    $this->placeOrder($customer, [$this->orderableSku()->id => 5]);

    $created = $this->tenantJson('POST', '/api/dashboard/customers', [
        'name' => 'مكتبة الأمل',
        'phone' => '01012345678',
        'addresses' => [['label' => 'المحل', 'governorate' => 'القاهرة', 'city' => 'مدينة نصر', 'address_line' => 'شارع عباس العقاد']],
    ])->assertCreated()->json('data');
    $invite = $this->tenantJson('POST', "/api/dashboard/customers/{$created['id']}/invite")->assertOk()->json('data');
    $detail = $this->tenantJson('GET', "/api/dashboard/customers/{$customer->id}")->assertOk()->json('data');
    $list = $this->tenantJson('GET', '/api/dashboard/customers')->assertOk()->json('data.0');

    expect($created['status'])->toBe(CustomerStatus::Invited->value);
    expectDocumentedShape($created, 'DashboardCustomer');
    expectDocumentedShape($created['addresses'][0], 'Address');
    expectDocumentedShape($invite, 'InviteResult');
    expectDocumentedShape($detail, 'DashboardCustomer');
    expectDocumentedShape($detail['recent_orders'][0], 'RecentOrder');
    expectDocumentedShape($list, 'DashboardCustomer');
});

test('orders, collections, the statement and notifications match their schemas', function (): void {
    $customer = $this->makeCustomer();
    $order = $this->placeOrder($customer, [$this->orderableSku()->id => 5]);

    $list = $this->tenantJson('GET', '/api/dashboard/orders')->assertOk()->json('data.0');
    $detail = $this->tenantJson('POST', "/api/dashboard/orders/{$order->number}/transition", ['to' => 'delivered', 'collect_full_amount' => true])->assertOk()->json('data');
    $collection = $this->tenantJson('GET', '/api/dashboard/collections')->assertOk()->json('data.0');
    $statement = $this->tenantJson('GET', "/api/dashboard/customers/{$customer->id}/statement")->assertOk();
    $notification = $this->tenantJson('GET', '/api/dashboard/notifications')->assertOk()->json('data.0');

    expectDocumentedShape($list, 'DashboardOrder');
    expectDocumentedShape($detail, 'DashboardOrderDetail');
    expectDocumentedShape($detail['items'][0], 'OrderItem');
    expectDocumentedShape($detail['history'][0], 'DashboardOrderHistoryEntry');
    expectDocumentedShape($detail['collections'][0], 'Collection');
    expectDocumentedShape($collection, 'Collection');
    expectDocumentedShape($collection['customer'], 'IdName');
    expectDocumentedShape($statement->json('data.0'), 'StatementOrder');
    expectDocumentedShape($statement->json('customer'), 'CustomerRef');
    expectDocumentedShape($statement->json('summary'), 'StatementSummary');
    expectDocumentedShape($notification, 'MerchantNotification');
});

test('the reports and the audit log match their schemas', function (): void {
    $customer = $this->makeCustomer();
    $sku = $this->orderableSku([], onHand: 100);
    $order = $this->placeOrder($customer, [$sku->id => 5]);
    $this->tenantJson('POST', "/api/dashboard/orders/{$order->number}/transition", ['to' => 'delivered'])->assertOk();

    $overview = $this->tenantJson('GET', '/api/dashboard/reports/overview')->assertOk()->json('data');
    $sales = $this->tenantJson('GET', '/api/dashboard/reports/sales')->assertOk()->json('data');
    $outstanding = $this->tenantJson('GET', '/api/dashboard/reports/outstanding')->assertOk()->json('data.0');
    $lowStock = $this->tenantJson('GET', '/api/dashboard/reports/low-stock')->assertOk()->json('data.0');
    $audit = $this->tenantJson('GET', '/api/dashboard/audit')->assertOk()->json('data.0');

    expectDocumentedShape($overview, 'OverviewReport');
    expectDocumentedShape($sales, 'SalesReport');
    expectDocumentedShape($outstanding, 'OutstandingCustomer');
    expectDocumentedShape($outstanding['customer'], 'CustomerRef');
    expectDocumentedShape($lowStock, 'LowStockSku');
    expectDocumentedShape($audit, 'AuditLog');
    expectDocumentedShape($audit['actor'], 'Actor');
});

test('users match the TenantUser schema', function (): void {
    expectDocumentedShape($this->tenantJson('GET', '/api/dashboard/users')->assertOk()->json('data.0'), 'TenantUser');
});
