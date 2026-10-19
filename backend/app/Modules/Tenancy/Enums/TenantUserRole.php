<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

/**
 * BR-T06, D82: the fixed platform roles of a dashboard user, each with a fixed set of permissions. Adding a role or
 * moving a permission is a code change; a store always keeps at least one active owner.
 */
enum TenantUserRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Sales = 'sales';
    case Warehouse = 'warehouse';
    case Accountant = 'accountant';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'مالك',
            self::Manager => 'مدير',
            self::Sales => 'مبيعات',
            self::Warehouse => 'مخزن',
            self::Accountant => 'محاسب',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Manager => array_values(array_filter(
                Permission::cases(),
                fn (Permission $permission): bool => ! in_array($permission, [Permission::SettingsManage, Permission::UsersManage], true),
            )),
            self::Sales => [Permission::OrdersView, Permission::OrdersManage, Permission::CustomersManage],
            self::Warehouse => [Permission::InventoryAdjust, Permission::OrdersView, Permission::OrdersManage, Permission::OrdersReturns],
            self::Accountant => [Permission::OrdersView, Permission::CustomersCredit, Permission::CollectionsManage, Permission::ReportsView],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
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
