<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ImportRowResult;
use App\Modules\Catalog\Enums\ImportRowAction;

test('a row result lists its errors with the row number', function (): void {
    $result = new ImportRowResult(7, ImportRowAction::Skip, [
        ['column' => 'sku_code', 'label' => 'كود الصنف', 'message' => 'كود الصنف مطلوب.'],
        ['column' => 'moq', 'label' => 'الحد الأدنى للطلب', 'message' => 'خطأ'],
    ]);

    expect($result->rowErrors())->toBe([
        ['row' => 7, 'column' => 'sku_code', 'label' => 'كود الصنف', 'message' => 'كود الصنف مطلوب.'],
        ['row' => 7, 'column' => 'moq', 'label' => 'الحد الأدنى للطلب', 'message' => 'خطأ'],
    ])->and($result->unexpected)->toBeFalse();
});

test('a row result without errors lists none', function (): void {
    expect((new ImportRowResult(3, ImportRowAction::Create))->rowErrors())->toBe([]);
});
