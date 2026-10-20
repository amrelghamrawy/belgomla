<?php

declare(strict_types=1);

use App\Modules\Tenancy\Enums\Permission;
use App\Modules\Tenancy\Enums\TenantUserRole;

/*
| BR-T06, D82: the role × permission table of tasks/v2/05-staff-roles.md, written out once. Every other test about
| who may do what follows from it.
*/

const ROLE_TABLE = [
    //                     owner manager sales warehouse accountant
    'catalog.manage' => [true, true, false, false, false],
    'pricing.manage' => [true, true, false, false, false],
    'inventory.adjust' => [true, true, false, true, false],
    'orders.view' => [true, true, true, true, true],
    'orders.manage' => [true, true, true, true, false],
    'orders.create' => [true, true, true, false, false],
    'orders.override_mov' => [true, true, false, false, false],
    'orders.returns' => [true, true, false, true, false],
    'customers.manage' => [true, true, true, false, false],
    'customers.credit' => [true, true, false, false, true],
    'collections.manage' => [true, true, false, false, true],
    'reports.view' => [true, true, false, false, true],
    'settings.manage' => [true, false, false, false, false],
    'users.manage' => [true, false, false, false, false],
    'audit.view' => [true, true, false, false, false],
];

dataset('role permissions', function (): array {
    $rows = [];
    $roles = [TenantUserRole::Owner, TenantUserRole::Manager, TenantUserRole::Sales, TenantUserRole::Warehouse, TenantUserRole::Accountant];

    foreach (ROLE_TABLE as $permission => $allowed) {
        foreach ($roles as $index => $role) {
            $rows["{$role->value} {$permission}"] = [$role, Permission::from($permission), $allowed[$index]];
        }
    }

    return $rows;
});

test('D82 each role has exactly the permissions of the table', function (TenantUserRole $role, Permission $permission, bool $allowed): void {
    expect($role->can($permission))->toBe($allowed)
        ->and(in_array($permission, $role->permissions(), true))->toBe($allowed);
})->with('role permissions');

test('the table covers every permission', function (): void {
    expect(array_keys(ROLE_TABLE))->toEqualCanonicalizing(array_map(fn (Permission $permission): string => $permission->value, Permission::cases()));
});

test('BR-T06 the five roles and their Arabic labels', function (): void {
    expect(TenantUserRole::toArray())->toBe(['owner' => 'مالك', 'manager' => 'مدير', 'sales' => 'مبيعات', 'warehouse' => 'مخزن', 'accountant' => 'محاسب']);
});

test('every permission has an Arabic label', function (): void {
    expect(array_filter(Permission::toArray(), fn (string $label): bool => $label === ''))->toBe([])
        ->and(Permission::toArray())->toHaveCount(15);
});
