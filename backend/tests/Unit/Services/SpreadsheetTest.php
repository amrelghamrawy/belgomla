<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Exceptions\InvalidImportFile;
use App\Modules\Catalog\Services\Spreadsheet;
use Illuminate\Support\Str;

function spreadsheetTempPath(SpreadsheetFormat $format): string
{
    return sys_get_temp_dir().'/spreadsheet-test-'.Str::uuid().'.'.$format->value;
}

const SPREADSHEET_HEADERS = ['sku_code' => 'كود الصنف', 'product_name' => 'اسم المنتج', 'base_price' => 'سعر الجملة'];

test('an xlsx file round trips with its arabic labels, the key row and the values', function (): void {
    $path = spreadsheetTempPath(SpreadsheetFormat::Xlsx);

    app(Spreadsheet::class)->write($path, SpreadsheetFormat::Xlsx, SPREADSHEET_HEADERS, [
        ['sku_code' => 'ADW-1', 'product_name' => 'الأضواء علوم أولى إعدادي', 'base_price' => 90.5],
        ['sku_code' => '0012', 'product_name' => 'كراسة', 'base_price' => 12],
    ], 'products_v1', withKeys: true);

    expect(spreadsheetRows($path))->toBe([
        1 => ['كود الصنف', 'اسم المنتج', 'سعر الجملة'],
        2 => ['sku_code', 'product_name', 'base_price'],
        3 => ['ADW-1', 'الأضواء علوم أولى إعدادي', '90.5'],
        4 => ['0012', 'كراسة', '12'],
    ])->and(app(Spreadsheet::class)->sheetName($path, SpreadsheetFormat::Xlsx))->toBe('products_v1');
});

test('a csv file round trips with the keys only and arabic text intact', function (): void {
    $path = spreadsheetTempPath(SpreadsheetFormat::Csv);

    app(Spreadsheet::class)->write($path, SpreadsheetFormat::Csv, SPREADSHEET_HEADERS, [
        ['sku_code' => 'ADW-1', 'product_name' => 'الأضواء، علوم "أولى"', 'base_price' => '90.00'],
    ], withKeys: true);

    expect(spreadsheetRows($path, SpreadsheetFormat::Csv))->toBe([
        1 => ['sku_code', 'product_name', 'base_price'],
        2 => ['ADW-1', 'الأضواء، علوم "أولى"', '90.00'],
    ])->and(app(Spreadsheet::class)->sheetName($path, SpreadsheetFormat::Csv))->toBeNull();
});

test('a csv file starts with a utf-8 bom so excel shows the arabic', function (): void {
    $path = spreadsheetTempPath(SpreadsheetFormat::Csv);

    app(Spreadsheet::class)->write($path, SpreadsheetFormat::Csv, SPREADSHEET_HEADERS, []);

    expect(mb_substr((string) file_get_contents($path), 0, 1))->toBe("\u{FEFF}")
        ->and(spreadsheetRows($path, SpreadsheetFormat::Csv))->toBe([1 => ['كود الصنف', 'اسم المنتج', 'سعر الجملة']]);
});

test('without keys an xlsx file has the label row only', function (): void {
    $path = spreadsheetTempPath(SpreadsheetFormat::Xlsx);

    app(Spreadsheet::class)->write($path, SpreadsheetFormat::Xlsx, SPREADSHEET_HEADERS, [['sku_code' => 'A']], 'قائمة الأسعار');

    expect(spreadsheetRows($path))->toBe([1 => ['كود الصنف', 'اسم المنتج', 'سعر الجملة'], 2 => ['A', '', '']])
        ->and(app(Spreadsheet::class)->sheetName($path, SpreadsheetFormat::Xlsx))->toBe('قائمة الأسعار');
});

test('an xlsx file gets further sheets after the first, each read back by its name', function (): void {
    $path = spreadsheetTempPath(SpreadsheetFormat::Xlsx);

    app(Spreadsheet::class)->write($path, SpreadsheetFormat::Xlsx, SPREADSHEET_HEADERS, [['sku_code' => 'A']], 'products_v3', withKeys: true, moreSheets: [
        ['name' => 'bundles_v1', 'headers' => ['bundle_code' => 'كود الباقة'], 'rows' => [['bundle_code' => 'SET-1']]],
    ]);

    expect(spreadsheetRows($path, sheetName: 'bundles_v1'))->toBe([1 => ['كود الباقة'], 2 => ['bundle_code'], 3 => ['SET-1']])
        ->and(spreadsheetRows($path))->toHaveCount(3)
        ->and(app(Spreadsheet::class)->sheetName($path, SpreadsheetFormat::Xlsx))->toBe('products_v3');
});

test('a csv file leaves the further sheets out', function (): void {
    $path = spreadsheetTempPath(SpreadsheetFormat::Csv);

    app(Spreadsheet::class)->write($path, SpreadsheetFormat::Csv, SPREADSHEET_HEADERS, [['sku_code' => 'A']], withKeys: true, moreSheets: [
        ['name' => 'bundles_v1', 'headers' => ['bundle_code' => 'كود الباقة'], 'rows' => [['bundle_code' => 'SET-1']]],
    ]);

    expect(spreadsheetRows($path, SpreadsheetFormat::Csv))->toBe([1 => ['sku_code', 'product_name', 'base_price'], 2 => ['A', '', '']]);
});

test('reading a sheet the file does not have gives no rows', function (): void {
    $path = spreadsheetTempPath(SpreadsheetFormat::Xlsx);

    app(Spreadsheet::class)->write($path, SpreadsheetFormat::Xlsx, SPREADSHEET_HEADERS, [['sku_code' => 'A']]);

    expect(spreadsheetRows($path, sheetName: 'bundles_v1'))->toBe([]);
});

test('reading keeps the spreadsheet row numbers across empty rows and trims cells', function (): void {
    $path = spreadsheetTempPath(SpreadsheetFormat::Csv);
    file_put_contents($path, "sku_code,product_name\n\n  A-1 , كتاب \n,\nB-2,قلم\n");

    expect(spreadsheetRows($path, SpreadsheetFormat::Csv))->toBe([
        1 => ['sku_code', 'product_name'],
        3 => ['A-1', 'كتاب'],
        5 => ['B-2', 'قلم'],
    ]);
});

test('a bom another tool left on the first cell is dropped', function (): void {
    $path = spreadsheetTempPath(SpreadsheetFormat::Csv);
    file_put_contents($path, "\u{FEFF}\u{FEFF}sku_code\nA-1\n");

    expect(spreadsheetRows($path, SpreadsheetFormat::Csv)[1])->toBe(['sku_code']);
});

test('numbers come back without exponent or trailing zeros', function (int|float $value, string $text): void {
    $path = spreadsheetTempPath(SpreadsheetFormat::Xlsx);

    app(Spreadsheet::class)->write($path, SpreadsheetFormat::Xlsx, ['n' => 'n'], [['n' => $value]]);

    expect(spreadsheetRows($path)[2])->toBe([$text]);
})->with([
    'whole float' => [90.0, '90'],
    'one decimal' => [7.5, '7.5'],
    'two decimals' => [7.58, '7.58'],
    'integer' => [400, '400'],
    'large' => [1234567890.25, '1234567890.25'],
]);

test('a file that is not a spreadsheet is a validation failure on the file', function (): void {
    $path = spreadsheetTempPath(SpreadsheetFormat::Xlsx);
    file_put_contents($path, 'not a zip');

    expect(fn (): array => spreadsheetRows($path))->toThrow(function (InvalidImportFile $e): void {
        expect($e->errorCode)->toBe('validation_failed')
            ->and($e->details)->toBe(['file' => ['تعذّرت قراءة الملف، ارفع ملف Excel (xlsx) أو CSV']]);
    });
});

test('the sheet name of an unreadable xlsx file is a validation failure too', function (): void {
    $path = spreadsheetTempPath(SpreadsheetFormat::Xlsx);
    file_put_contents($path, 'not a zip');

    expect(fn () => app(Spreadsheet::class)->sheetName($path, SpreadsheetFormat::Xlsx))->toThrow(InvalidImportFile::class);
});
