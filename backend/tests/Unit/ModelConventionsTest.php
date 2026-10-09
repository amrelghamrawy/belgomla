<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\TenantOrderCounter;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Runtime model conventions (pinkary tests/Arch/ModelsTest.php, adapted)
|--------------------------------------------------------------------------
| These need a booted app and a migrated database, so they live in Unit.
*/

/** Models that intentionally have no factory: 1:1 rows created by their parent, append-only logs. */
const MODELS_WITHOUT_FACTORY = [TenantSetting::class, AuditLog::class];

/** Platform-level models that are not tenant-owned. */
const PLATFORM_MODELS = [Tenant::class, TenantSetting::class];

/**
 * Columns of a model's table whose name ends with one of the suffixes, minus the timestamps.
 *
 * @param  class-string<Model>  $model
 * @param  list<string>  $suffixes
 * @return list<string>
 */
function columnsEndingWith(string $model, array $suffixes): array
{
    $table = (new $model)->getTable();

    return collect(Schema::getColumnListing($table))
        ->reject(fn (string $column): bool => in_array($column, ['created_at', 'updated_at'], true))
        ->filter(fn (string $column): bool => array_any($suffixes, fn (string $suffix): bool => str_ends_with($column, $suffix)))
        ->values()
        ->all();
}

test('the module model list is explicit so new models are reviewed', function (): void {
    expect(moduleModels())->toBe([
        AuditLog::class,
        Category::class,
        Product::class,
        ProductFieldDefinition::class,
        ProductFieldOption::class,
        ProductImage::class,
        ProductRelation::class,
        Sku::class,
        Unit::class,
        Cart::class,
        CartItem::class,
        Customer::class,
        CustomerAddress::class,
        CustomerInvitation::class,
        CustomerOtp::class,
        InventoryMovement::class,
        SkuInventory::class,
        OutboundMessage::class,
        MerchantNotification::class,
        Order::class,
        OrderItem::class,
        OrderStatusHistory::class,
        TenantOrderCounter::class,
        SkuPriceTier::class,
        Tenant::class,
        TenantSetting::class,
        TenantUser::class,
    ]);
});

test('every model has a factory', function (string $model): void {
    expect($model::factory())->toBeInstanceOf(Factory::class);
})->with(fn (): array => array_values(array_diff(moduleModels(), MODELS_WITHOUT_FACTORY)));

test('every tenant-owned table uses BelongsToTenant and has a tenant_id column', function (string $model): void {
    expect(Schema::hasColumn((new $model)->getTable(), 'tenant_id'))->toBeTrue("{$model} has no tenant_id column")
        ->and(class_uses_recursive($model))->toContain(BelongsToTenant::class);
})->with(fn (): array => array_values(array_diff(moduleModels(), PLATFORM_MODELS)));

test('every *_at column is cast to datetime', function (): void {
    $uncast = [];

    foreach (moduleModels() as $model) {
        $casts = (new $model)->getCasts();

        foreach (columnsEndingWith($model, ['_at']) as $column) {
            if (($casts[$column] ?? null) !== 'datetime') {
                $uncast[] = "{$model}::{$column}";
            }
        }
    }

    expect($uncast)->toBe([]);
});

test('every money column is cast to decimal:2', function (): void {
    $uncast = [];

    foreach (moduleModels() as $model) {
        $casts = (new $model)->getCasts();

        foreach (columnsEndingWith($model, ['_amount', '_price', '_total']) as $column) {
            if (($casts[$column] ?? null) !== 'decimal:2') {
                $uncast[] = "{$model}::{$column}";
            }
        }
    }

    expect($uncast)->toBe([]);
});
