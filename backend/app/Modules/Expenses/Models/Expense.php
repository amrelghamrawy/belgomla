<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Models;

use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * D100: one thing the shop spent to run (rent, salaries, transport…), on a business day. Voided with a reason, never
 * edited or deleted. `created_by` / `voided_by` are tenant user ids; Tenancy models stay inside Tenancy, so there is
 * no relation to them.
 *
 * @property int $id
 * @property int $tenant_id
 * @property CarbonImmutable $spent_on
 * @property string $amount
 * @property ExpenseCategory $category
 * @property string|null $note
 * @property int|null $created_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property int|null $voided_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Expense extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    protected static function newFactory(): ExpenseFactory
    {
        return ExpenseFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'amount' => 'decimal:2',
            'category' => ExpenseCategory::class,
            'created_by' => 'integer',
            'voided_at' => 'datetime',
            'voided_by' => 'integer',
        ];
    }
}
