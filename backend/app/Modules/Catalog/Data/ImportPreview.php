<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

/**
 * The dry run of an uploaded catalog file (V2 Task 06): the token that imports it, how many rows would create or
 * update a product and how many would be skipped, and the first `catalog.import_preview_errors` row errors.
 * A read model, not request input.
 */
final readonly class ImportPreview
{
    /**
     * @param  list<array{row: int, column: string, label: string, message: string}>  $errors
     */
    public function __construct(
        public string $token,
        public string $fileName,
        public int $total,
        public int $create,
        public int $update,
        public int $skip,
        public array $errors,
        public bool $errorsTruncated,
    ) {}
}
