<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Enums\ImportRowAction;

/**
 * What one row of a catalog import did, or would do in the preview (V2 Task 06): its spreadsheet row number, the
 * action, and the problems that made it a `skip` as `{column, label, message}` (column = the header key, label =
 * the Arabic header the merchant sees). `unexpected` marks a row the database refused for a reason the file cannot
 * explain (counted as `failed`, not `skipped`). A read model, not request input.
 */
final readonly class ImportRowResult
{
    /**
     * @param  list<array{column: string, label: string, message: string}>  $errors
     */
    public function __construct(
        public int $row,
        public ImportRowAction $action,
        public array $errors = [],
        public bool $unexpected = false,
    ) {}

    /**
     * The errors with their row number, as the preview and the `imports` row list them.
     *
     * @return list<array{row: int, column: string, label: string, message: string}>
     */
    public function rowErrors(): array
    {
        return array_map(fn (array $error): array => ['row' => $this->row, ...$error], $this->errors);
    }
}
