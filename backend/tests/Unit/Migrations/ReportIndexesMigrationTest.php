<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| V2 Task 16 (D94): the report indexes. The migration is rolled back first, inside the test's transaction (PostgreSQL
| DDL is transactional), then run forward (and back). Nothing outlives the test.
*/

function reportIndexesMigration(): Migration
{
    $migration = require database_path('migrations/2026_11_05_100000_add_report_indexes.php');

    return $migration instanceof Migration ? $migration : throw new RuntimeException('not a migration');
}

/**
 * @return list<string>
 */
function reportIndexes(): array
{
    return DB::table('pg_indexes')
        ->whereIn('indexname', ['orders_tenant_status_delivered_index', 'inventory_movements_tenant_type_created_index'])
        ->orderBy('indexname')
        ->pluck('indexname')
        ->all();
}

test('D94 delivered orders and stock movements by type are indexed for the reports', function (): void {
    reportIndexesMigration()->down();

    reportIndexesMigration()->up();

    expect(reportIndexes())->toBe(['inventory_movements_tenant_type_created_index', 'orders_tenant_status_delivered_index']);
});

test('the migration rolls back to no report index', function (): void {
    reportIndexesMigration()->down();

    expect(reportIndexes())->toBe([]);
});
