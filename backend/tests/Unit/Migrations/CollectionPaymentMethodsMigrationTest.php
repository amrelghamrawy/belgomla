<?php

declare(strict_types=1);

use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function paymentMethodsMigration(): Migration
{
    $migration = require database_path('migrations/2026_11_09_100000_add_collection_payment_methods.php');

    return $migration instanceof Migration ? $migration : throw new RuntimeException('not a migration');
}

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('D103 d old rows stay cash, and the migration rolls back to the two-method shape when no new code is used', function (): void {
    $cash = CashCollection::factory()->create(['reference' => 'R-1']);

    paymentMethodsMigration()->down();

    expect(Schema::hasColumn('collections', 'reference'))->toBeFalse()
        ->and(DB::table('collections')->where('id', $cash->id)->value('method'))->toBe('cash')
        ->and(fn () => DB::transaction(fn () => DB::table('collections')->where('id', $cash->id)->update(['method' => 'instapay'])))->toThrow(QueryException::class);

    paymentMethodsMigration()->up();

    expect(Schema::hasColumn('collections', 'reference'))->toBeTrue()
        ->and(DB::table('collections')->where('id', $cash->id)->update(['method' => 'instapay']))->toBe(1);
});

test('D103 the rollback is refused while a payment or a return uses a new method, and changes nothing', function (): void {
    CashCollection::factory()->create(['method' => CollectionMethod::Wallet, 'reference' => 'VC-1']);

    expect(fn () => paymentMethodsMigration()->down())->toThrow(RuntimeException::class, 'Cannot roll back payment methods')
        ->and(Schema::hasColumn('collections', 'reference'))->toBeTrue();
});
