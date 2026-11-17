<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

/**
 * BR-T06, D82: what a dashboard user may do. Each role has a fixed set (TenantUserRole::permissions()); dashboard
 * routes require one with the `permission:` middleware. Reading the catalog, stock and orders needs `orders.view`,
 * which every role has.
 */
enum Permission: string
{
    case CatalogManage = 'catalog.manage';
    case PricingManage = 'pricing.manage';
    case PricingCustomers = 'pricing.customers';
    case InventoryAdjust = 'inventory.adjust';
    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';
    case OrdersCreate = 'orders.create';
    case OrdersOverrideMov = 'orders.override_mov';
    case OrdersEdit = 'orders.edit';
    case OrdersDiscount = 'orders.discount';
    case OrdersReturns = 'orders.returns';
    case CustomersManage = 'customers.manage';
    case CustomersCredit = 'customers.credit';
    case CollectionsManage = 'collections.manage';
    case ExpensesManage = 'expenses.manage';
    case ReportsView = 'reports.view';
    case SettingsManage = 'settings.manage';
    case UsersManage = 'users.manage';
    case AuditView = 'audit.view';

    public function label(): string
    {
        return match ($this) {
            self::CatalogManage => 'إدارة المنتجات والتصنيفات',
            self::PricingManage => 'إدارة الأسعار',
            self::PricingCustomers => 'مستويات العملاء وأسعارهم الخاصة',
            self::InventoryAdjust => 'تعديل المخزون',
            self::OrdersView => 'عرض الطلبات',
            self::OrdersManage => 'إدارة الطلبات',
            self::OrdersCreate => 'تسجيل طلبات للعملاء',
            self::OrdersOverrideMov => 'تجاوز الحد الأدنى للطلب',
            self::OrdersEdit => 'تعديل أصناف الطلبات',
            self::OrdersDiscount => 'خصومات الطلبات',
            self::OrdersReturns => 'تسجيل المرتجعات',
            self::CustomersManage => 'إدارة العملاء',
            self::CustomersCredit => 'حدود الائتمان ومهل السداد',
            self::CollectionsManage => 'التحصيل',
            self::ExpensesManage => 'المصروفات',
            self::ReportsView => 'التقارير',
            self::SettingsManage => 'إعدادات المتجر',
            self::UsersManage => 'إدارة المستخدمين',
            self::AuditView => 'سجل العمليات',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function toArray(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
