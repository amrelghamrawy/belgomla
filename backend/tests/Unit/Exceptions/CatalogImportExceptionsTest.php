<?php

declare(strict_types=1);

use App\Modules\Catalog\Exceptions\ImportUnavailable;
use App\Modules\Catalog\Exceptions\InvalidImportFile;
use App\Modules\Catalog\Exceptions\InvalidImportRow;
use App\Modules\Catalog\Exceptions\PricesHidden;

test('a file that cannot be imported as a whole is a validation failure on the file', function (InvalidImportFile $e, string $message): void {
    expect([$e->status, $e->errorCode, $e->getMessage()])->toBe([422, 'validation_failed', 'بعض البيانات غير صحيحة'])
        ->and($e->details)->toBe(['file' => [$message]]);
})->with([
    'unreadable' => [InvalidImportFile::unreadable(), 'تعذّرت قراءة الملف، ارفع ملف Excel (xlsx) أو CSV'],
    'another version' => [InvalidImportFile::unsupportedVersion('products_v2'), 'إصدار الملف «products_v2» غير مدعوم، نزّل قالبًا جديدًا'],
    'missing columns' => [fn (): InvalidImportFile => InvalidImportFile::missingColumns(['كود الصنف', 'سعر الجملة']), 'أعمدة ناقصة في الملف: كود الصنف، سعر الجملة'],
    'no header' => [InvalidImportFile::noHeader(), 'لم يتم التعرف على صف العناوين، استخدم القالب'],
    'empty' => [InvalidImportFile::empty(), 'الملف لا يحتوي على منتجات'],
]);

test('a row error keeps its messages by column', function (): void {
    $e = InvalidImportRow::withErrors(['base_price' => ['سعر الجملة مطلوب.']]);

    expect([$e->status, $e->errorCode])->toBe([422, 'validation_failed'])
        ->and($e->details)->toBe(['base_price' => ['سعر الجملة مطلوب.']]);
});

test('an expired preview token, an export not ready and a missing export have their own answers', function (): void {
    expect([ImportUnavailable::expired()->status, ImportUnavailable::expired()->errorCode, ImportUnavailable::expired()->getMessage()])
        ->toBe([422, 'import_expired', 'انتهت صلاحية الملف المرفوع، ارفعه مرة أخرى'])
        ->and([ImportUnavailable::exportNotReady()->status, ImportUnavailable::exportNotReady()->errorCode, ImportUnavailable::exportNotReady()->getMessage()])
        ->toBe([409, 'export_not_ready', 'الملف قيد التجهيز، حاول بعد قليل'])
        ->and([ImportUnavailable::exportMissing()->status, ImportUnavailable::exportMissing()->errorCode])->toBe([404, 'not_found']);
});

test('D1 a guest asking for the price list file of a store that hides prices is told to log in', function (): void {
    $e = PricesHidden::forGuest();

    expect([$e->status, $e->errorCode, $e->getMessage()])->toBe([403, 'prices_hidden', 'سجّل الدخول لعرض الأسعار والإضافة إلى السلة']);
});
