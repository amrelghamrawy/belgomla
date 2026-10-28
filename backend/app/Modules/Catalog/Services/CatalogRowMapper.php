<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Data\CatalogLookups;
use App\Modules\Catalog\Data\ImportRowData;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\InvalidImportFile;
use App\Modules\Catalog\Exceptions\InvalidImportRow;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Services\PriceResolver;
use App\Support\ApiException;
use App\Support\Money;
use Generator;
use Illuminate\Support\Facades\Validator;

/**
 * The catalog file format, version 2 (V2 Task 13; version 1 was V2 Task 06): one row per unit a SKU is sold in (D91).
 * Turns the tenant's catalog into rows for the export and file rows back into what CreateProduct / UpdateProduct take.
 *
 *  - Header ↔ keys: the key row (`sku_code, product_name, …`) is the first or second row (the export puts the
 *    Arabic labels above it); a file with only the Arabic label row is read through the labels. Unknown columns are
 *    ignored; a missing required column fails the whole file. The XLSX sheet is named `products_v2`; a `products_v1`
 *    file (its `selling_unit` column read as `unit`) is still read, each row then being a SKU in one unit.
 *  - A SKU's units are consecutive rows with its code; the first row holds the product, the SKU's base unit, its
 *    stock and custom fields (they are read from it only), every row one unit: its name, factor, prices, MOQ, tiers and
 *    `unit_is_default` (نعم on at most one row). A unit the SKU has but the file does not list is kept as it is; with no
 *    default named, an existing SKU keeps its default and a new one takes its first row.
 *  - References are resolved by name: the category by its full path ("كتب خارجية > أولى إعدادي", D25; never
 *    created, archived only when the product already sits there), units by name (BR-U01), select fields by option
 *    label (BR-P08). A field of another section than the product's may only repeat the stored value (D69).
 *  - Every SKU is validated with StoreProductRequest's own rules through a Validator, and the product data comes
 *    from its typed accessors, so the file obeys exactly what the API does. Each error names its row and column.
 *  - Optional columns left out of the file keep what the product has; an empty cell in a column that is there
 *    clears the value (description, reference price, %, a custom field) or, for MOQ and On Hand, keeps it.
 *    When any tier column is there, the tiers in a unit's row replace that unit's (D34).
 *  - A percentage wins over a price (BR-PR10), except that a row repeating the stored price and percentage keeps
 *    both, so exporting and importing the same file changes nothing (D95).
 *
 * @phpstan-type CellValue string|int|float|bool|null
 * @phpstan-type RowError array{row?: int, column: string, label: string, message: string}
 */
final readonly class CatalogRowMapper
{
    public const string SHEET_NAME = 'products_v2';

    /** The sheet names of the versions this mapper reads. */
    public const array READABLE_SHEETS = ['products_v1', 'products_v2'];

    public const string FIELD_PREFIX = 'field:';

    public const string PATH_SEPARATOR = ' > ';

    public const int TIER_COLUMNS = 5;

    /** The columns every file must have; `base_price` may be empty when `base_price_percent` is given. */
    public const array REQUIRED = ['sku_code', 'product_name', 'category_path', 'unit', 'base_unit', 'conversion_factor', 'base_price'];

    /** The fixed columns before the tiers, then after them `on_hand`, then one per custom field. */
    public const array LABELS = [
        'sku_code' => 'كود الصنف',
        'product_name' => 'اسم المنتج',
        'category_path' => 'التصنيف',
        'status' => 'الحالة',
        'description' => 'الوصف',
        'base_unit' => 'الوحدة الأساسية',
        'unit' => 'وحدة البيع',
        'unit_is_default' => 'الوحدة الافتراضية',
        'conversion_factor' => 'عدد الوحدات الأساسية في وحدة البيع',
        'cover_price' => 'السعر المرجعي (الغلاف أو القطاعي)',
        'base_price' => 'سعر الجملة',
        'base_price_percent' => 'نسبة الخصم من السعر المرجعي %',
        'moq' => 'الحد الأدنى للطلب',
    ];

    public const string ON_HAND_LABEL = 'المخزون (بالوحدة الأساسية)';

    /** Pseudo columns of row errors that belong to no single cell. */
    public const array OTHER_LABELS = ['tiers' => 'شرائح السعر', 'row' => 'الصف'];

    /** Keys of format v1 read as today's. */
    private const array ALIASES = ['selling_unit' => 'unit'];

    /** Validation keys of the product request → file columns (the first row of the SKU). */
    private const array COLUMN_OF = [
        'name' => 'product_name',
        'category_id' => 'category_path',
        'description' => 'description',
        'status' => 'status',
        'opening_stock' => 'on_hand',
        'sku.code' => 'sku_code',
        'sku.base_unit_id' => 'base_unit',
        'sku.units' => 'unit',
        'base_price' => 'base_price',
        'tiers' => 'tiers',
    ];

    /** Validation keys under `sku.units.{i}.` → the columns of unit i's row. */
    private const array UNIT_COLUMN_OF = [
        'id' => 'unit',
        'unit_id' => 'unit',
        'conversion_factor' => 'conversion_factor',
        'cover_price' => 'cover_price',
        'base_price' => 'base_price',
        'base_price_percent' => 'base_price_percent',
        'moq' => 'moq',
        'is_default' => 'unit_is_default',
        'tiers' => 'tiers',
    ];

    /** Errors without details, by code → the column they are about. */
    private const array COLUMN_OF_CODE = [
        'duplicate_code' => 'sku_code',
        'invalid_transition' => 'status',
        'inventory_below_reserved' => 'on_hand',
        'cover_price_required' => 'cover_price',
    ];

    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*(:[a-z0-9_]+)?$/';

    private const array TRUE_WORDS = ['نعم', 'true', '1', 'yes'];

    private const array FALSE_WORDS = ['لا', 'false', '0', 'no'];

    public function __construct(private PriceResolver $prices) {}

    public function lookups(): CatalogLookups
    {
        $categories = [];

        foreach (Category::query()->orderBy('path')->orderBy('id')->get() as $category) {
            $categories[$category->id] = $category;
        }

        $paths = [];
        $byPath = [];

        foreach ($categories as $id => $category) {
            $names = array_map(
                fn (string $ancestor): string => isset($categories[(int) $ancestor]) ? $categories[(int) $ancestor]->name : '',
                array_values(array_filter(explode('/', $category->path), fn (string $part): bool => $part !== '')),
            );
            $paths[$id] = implode(self::PATH_SEPARATOR, $names);
            $byPath[$this->foldPath($paths[$id])][] = $id;
        }

        $units = [];

        foreach (Unit::query()->orderBy('id')->get() as $unit) {
            $units[$this->fold($unit->name)] ??= $unit;
        }

        $mostTiers = SkuUnit::query()->withCount('tiers')->orderByDesc('tiers_count')->first()?->getAttribute('tiers_count');

        return new CatalogLookups(
            categories: $categories,
            paths: $paths,
            byPath: $byPath,
            units: $units,
            fields: $this->activeFields(),
            tierColumns: max(self::TIER_COLUMNS, is_int($mostTiers) ? $mostTiers : 0),
        );
    }

    /**
     * The active custom field definitions with their options, in form order, by key.
     *
     * @return array<string, ProductFieldDefinition>
     */
    private function activeFields(): array
    {
        $fields = [];
        $definitions = ProductFieldDefinition::query()->with('options')->where('status', ArchiveStatus::Active)->orderBy('sort_order')->orderBy('id')->get();

        foreach ($definitions as $definition) {
            $fields[$definition->key] = $definition;
        }

        return $fields;
    }

    /**
     * The file's columns in order, key => Arabic label.
     *
     * @return array<string, string>
     */
    public function columns(CatalogLookups $lookups): array
    {
        $columns = self::LABELS;

        for ($tier = 1; $tier <= $lookups->tierColumns; $tier++) {
            $columns["tier_{$tier}_min"] = "شريحة {$tier}: من كمية";
            $columns["tier_{$tier}_price"] = "شريحة {$tier}: السعر";
        }

        $columns['on_hand'] = self::ON_HAND_LABEL;

        foreach ($lookups->fields as $key => $definition) {
            $columns[self::FIELD_PREFIX.$key] = $definition->label;
        }

        return $columns;
    }

    public function label(string $column, CatalogLookups $lookups): string
    {
        if (preg_match('/^tier_(\d+)_(min|price)$/', $column, $match) === 1) {
            return $match[2] === 'min' ? "شريحة {$match[1]}: من كمية" : "شريحة {$match[1]}: السعر";
        }

        return $this->columns($lookups)[$column] ?? self::OTHER_LABELS[$column] ?? $column;
    }

    /**
     * File rows (row number => cells) as maps by column key, header rows left out. Every key of the header is in
     * every row (empty string for an empty cell), so a column that is there can be told from one that is not.
     *
     * @param  iterable<int, list<string>>  $raw
     * @return Generator<int, array<string, string>>
     */
    public function rows(iterable $raw, CatalogLookups $lookups, ?string $sheetName = null): Generator
    {
        if ($sheetName !== null && preg_match('/^products_v\d+$/', $sheetName) === 1 && ! in_array($sheetName, self::READABLE_SHEETS, true)) {
            throw InvalidImportFile::unsupportedVersion($sheetName);
        }

        $header = null;
        $first = null;

        foreach ($raw as $number => $cells) {
            if ($header === null) {
                if ($this->isKeyRow($cells)) {
                    $header = $this->checked(array_map(fn (string $cell): ?string => $this->isColumn($cell, $lookups) ? (self::ALIASES[$cell] ?? $cell) : null, $cells), $lookups);

                    continue;
                }

                if ($first === null) {
                    $first = $cells;

                    continue;
                }

                $header = $this->checked($this->fromLabels($first, $lookups) ?? throw InvalidImportFile::noHeader(), $lookups);
            }

            $row = [];

            foreach ($header as $index => $key) {
                if ($key !== null) {
                    $row[$key] = $cells[$index] ?? '';
                }
            }

            yield $number => $row;
        }

        if ($header === null && ($first === null || $this->fromLabels($first, $lookups) !== null)) {
            throw InvalidImportFile::empty();
        }

        if ($header === null) {
            throw InvalidImportFile::noHeader();
        }
    }

    /**
     * The rows of one SKU together (D91): consecutive rows with the same code are its units; a row without a code is
     * alone. Keyed by the number of the SKU's first row.
     *
     * @param  iterable<int, array<string, string>>  $rows
     * @return Generator<int, non-empty-array<int, array<string, string>>> first row number => (row number => row)
     */
    public function skus(iterable $rows): Generator
    {
        $group = [];
        $code = '';

        foreach ($rows as $number => $row) {
            $rowCode = $row['sku_code'] ?? '';

            if ($group !== [] && ($rowCode === '' || $rowCode !== $code)) {
                yield array_key_first($group) => $group;
                $group = [];
            }

            $group[$number] = $row;
            $code = $rowCode;
        }

        if ($group !== []) {
            yield array_key_first($group) => $group;
        }
    }

    /**
     * Resolves and validates the rows of one SKU (row number => row, the product on the first). `$existing` is the
     * product whose SKU has the rows' code (an update), with `sku.activeUnits.tiers` loaded.
     *
     * @param  non-empty-array<int, array<string, string>>  $rows
     *
     * @throws InvalidImportRow
     */
    public function map(array $rows, CatalogLookups $lookups, ?Product $existing = null): ImportRowData
    {
        $numbers = array_keys($rows);
        $first = $rows[$numbers[0]];
        $errors = [];
        $sku = $existing?->sku;
        $category = $this->category($first['category_path'] ?? '', $lookups, $existing?->category_id, $errors);
        $baseUnit = $this->unit($first, 'base_unit', $lookups, $sku?->base_unit_id, $errors);
        $kind = $category->kind ?? $existing?->category->kind;
        $customFields = $this->customFields($first, $lookups, $kind, $existing, $errors);
        $errors = $errors === [] ? [] : [$numbers[0] => $errors];

        $units = [];
        $tierNumbers = [];

        foreach (array_values($rows) as $index => $row) {
            $unitErrors = [];
            $units[] = $this->unitPayload($row, $lookups, $sku, $unitErrors);
            $tierNumbers[$index] = array_keys($this->tiers($row) ?? []);

            if ($unitErrors !== []) {
                $errors[$numbers[$index]] = [...($errors[$numbers[$index]] ?? []), ...$unitErrors];
            }
        }

        $payload = [
            'name' => $first['product_name'] ?? '',
            'category_id' => $category?->id,
            'description' => array_key_exists('description', $first) ? $this->blankToNull($first['description']) : $existing?->description,
            'custom_fields' => $customFields,
            'sku' => [
                'code' => $first['sku_code'] ?? '',
                'base_unit_id' => $baseUnit?->id,
                'units' => $units,
            ],
            ...(($first['status'] ?? '') !== '' ? ['status' => $this->status($first['status'])] : []),
            ...(($first['on_hand'] ?? '') !== '' ? ['opening_stock' => $first['on_hand']] : []),
        ];

        $request = new StoreProductRequest($payload);
        $validator = Validator::make($payload, $request->rules(), [], $this->attributes($lookups, $tierNumbers));

        foreach ($validator->errors()->messages() as $key => $messages) {
            [$index, $column] = $this->columnOf($key, $tierNumbers);
            $errors[$numbers[$index] ?? $numbers[0]][$column] ??= array_values(array_filter($messages, is_string(...)));
        }

        $defaults = array_keys(array_filter($units, fn (array $unit): bool => $unit['is_default'] === true));

        if (count($defaults) > 1) {
            $errors[$numbers[$defaults[1]]]['unit_is_default'] = ['وحدة افتراضية واحدة فقط لكل صنف'];
        }

        if ($errors !== []) {
            throw InvalidImportRow::inRows($errors);
        }

        return new ImportRowData(
            product: $this->withStoredUnits(ProductData::fromRequest($request), array_values($rows), $sku, $defaults !== []),
            status: $request->status(),
            onHand: array_key_exists('opening_stock', $payload) ? $request->openingStock() : null,
        );
    }

    /**
     * The row errors an exception stands for, each pointed at a row and a column of the file. `$rows` are the numbers of
     * the SKU's rows, unit by unit (an error about unit i is on its row; any other on the first).
     *
     * @param  non-empty-list<int>  $rows
     * @return list<RowError>
     */
    public function errors(ApiException $e, CatalogLookups $lookups, array $rows = [0]): array
    {
        $errors = [];

        if ($e instanceof InvalidImportRow && $e->items !== null) {
            foreach ($e->items as $item) {
                $column = is_string($item['column'] ?? null) ? $item['column'] : 'row';
                $errors[] = ['row' => is_int($item['row'] ?? null) ? $item['row'] : $rows[0], 'column' => $column, 'label' => $this->label($column, $lookups), 'message' => is_string($item['message'] ?? null) ? $item['message'] : ''];
            }

            return $errors;
        }

        if (in_array($e->errorCode, ['validation_failed', 'invalid_tiers', 'cover_price_required'], true) && $e->details !== null) {
            foreach ($e->details as $key => $messages) {
                [$index, $column] = $this->columnOf($key, []);

                foreach (is_array($messages) ? $messages : [$messages] as $message) {
                    if (is_string($message)) {
                        $errors[] = ['row' => $rows[$index] ?? $rows[0], 'column' => $column, 'label' => $this->label($column, $lookups), 'message' => $message];
                    }
                }
            }
        }

        if ($errors === []) {
            $column = self::COLUMN_OF_CODE[$e->errorCode] ?? 'row';
            $errors[] = ['row' => $rows[0], 'column' => $column, 'label' => $this->label($column, $lookups), 'message' => $e->getMessage()];
        }

        return $errors;
    }

    /**
     * The export rows of a product: one per active unit of its SKU, the default first (D91), each with the product, its
     * SKU, stock and custom fields, and the unit's prices and tiers. Money is written as numbers so the sheet can compute
     * with it.
     *
     * @return list<array<string, CellValue>>
     */
    public function exportRows(Product $product, CatalogLookups $lookups): array
    {
        $sku = $product->sku;
        $units = $sku instanceof Sku ? $sku->activeUnits->all() : [];

        return array_map(fn (?SkuUnit $unit): array => $this->exportRow($product, $unit, $lookups), $units === [] ? [null] : array_values($units));
    }

    /**
     * @return array<string, CellValue>
     */
    private function exportRow(Product $product, ?SkuUnit $unit, CatalogLookups $lookups): array
    {
        $sku = $product->sku;
        $row = [
            'sku_code' => $sku?->code,
            'product_name' => $product->name,
            'category_path' => $lookups->paths[$product->category_id] ?? '',
            'status' => $product->status->label(),
            'description' => $product->description,
            'base_unit' => $sku?->baseUnit->name,
            'unit' => $unit?->unit->name,
            'unit_is_default' => $unit instanceof SkuUnit ? $unit->is_default ? 'نعم' : 'لا' : (null),
            'conversion_factor' => $unit?->conversion_factor,
            'cover_price' => $this->number($unit?->cover_price),
            'base_price' => $this->number($unit?->base_price),
            'base_price_percent' => $this->number($unit?->base_price_percent),
            'moq' => $unit?->moq,
            'on_hand' => $sku->inventory->on_hand ?? 0,
        ];

        $tier = 0;

        foreach ($unit instanceof SkuUnit ? $unit->tiers->sortBy('min_quantity') : [] as $stored) {
            $tier++;
            $row["tier_{$tier}_min"] = $stored->min_quantity;
            $row["tier_{$tier}_price"] = $this->number($stored->unit_price);
        }

        foreach ($lookups->fields as $key => $definition) {
            $row[self::FIELD_PREFIX.$key] = $this->exportField($definition, $product->custom_fields[$key] ?? null);
        }

        return $row;
    }

    /**
     * One unit of the SKU as the product request takes it, from its row: the unit by name (an archived one only when the
     * SKU already sells in it), the stored unit's id when the SKU has it, and the cells.
     *
     * @param  array<string, string>  $row
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    private function unitPayload(array $row, CatalogLookups $lookups, ?Sku $sku, array &$errors): array
    {
        $named = $lookups->units[$this->fold($row['unit'] ?? '')] ?? null;
        $stored = $named instanceof Unit ? $sku?->activeUnits->firstWhere('unit_id', $named->id) : null;
        $unit = $this->unit($row, 'unit', $lookups, $stored?->unit_id, $errors);
        $tiers = $this->tiers($row);
        $default = $this->yesNo($row['unit_is_default'] ?? '');

        if (($row['unit_is_default'] ?? '') !== '' && $default === null) {
            $errors['unit_is_default'] = ['اكتب نعم أو لا'];
        }

        return [
            'id' => $stored?->id,
            'unit_id' => $unit?->id,
            'conversion_factor' => $row['conversion_factor'] ?? '',
            'cover_price' => array_key_exists('cover_price', $row) ? $this->blankToNull($row['cover_price']) : $stored?->cover_price,
            'base_price' => $this->blankToNull($row['base_price'] ?? ''),
            'base_price_percent' => $this->blankToNull($row['base_price_percent'] ?? ''),
            'moq' => ($row['moq'] ?? '') !== '' ? $row['moq'] : ($stored->moq ?? 1),
            'is_default' => $default === true,
            ...($tiers === null ? [] : ['tiers' => array_values($tiers)]),
        ];
    }

    /**
     * @param  list<string>  $cells
     */
    private function isKeyRow(array $cells): bool
    {
        $filled = array_filter($cells, fn (string $cell): bool => $cell !== '');

        return in_array('sku_code', $filled, true)
            && array_all($filled, fn (string $cell): bool => preg_match(self::KEY_PATTERN, $cell) === 1);
    }

    /** A key of the format: a fixed column (or its v1 name), a tier column (any number) or an active custom field. */
    private function isColumn(string $key, CatalogLookups $lookups): bool
    {
        return array_key_exists($key, self::LABELS)
            || array_key_exists($key, self::ALIASES)
            || $key === 'on_hand'
            || preg_match('/^tier_\d+_(min|price)$/', $key) === 1
            || (str_starts_with($key, self::FIELD_PREFIX) && array_key_exists(mb_substr($key, mb_strlen(self::FIELD_PREFIX)), $lookups->fields));
    }

    /**
     * The header read through the Arabic labels, or null when the row does not name the code column.
     *
     * @param  list<string>  $cells
     * @return list<string|null>|null
     */
    private function fromLabels(array $cells, CatalogLookups $lookups): ?array
    {
        $byLabel = [];

        foreach ($this->columns($lookups) as $key => $label) {
            $byLabel[$this->fold($label)] ??= $key;
        }

        $header = array_map(fn (string $cell): ?string => $byLabel[$this->fold($cell)] ?? null, $cells);

        return in_array('sku_code', $header, true) ? $header : null;
    }

    /**
     * @param  list<string|null>  $header
     * @return list<string|null>
     */
    private function checked(array $header, CatalogLookups $lookups): array
    {
        $missing = array_values(array_diff(self::REQUIRED, array_filter($header, is_string(...))));

        if ($missing !== []) {
            throw InvalidImportFile::missingColumns(array_map(fn (string $column): string => $this->label($column, $lookups), $missing));
        }

        return $header;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function category(string $path, CatalogLookups $lookups, ?int $current, array &$errors): ?Category
    {
        if ($path === '') {
            return null;
        }

        $ids = $lookups->byPath[$this->foldPath($path)] ?? [];

        if (count($ids) !== 1) {
            $errors['category_path'] = [$ids === [] ? 'التصنيف غير موجود، أنشئه أولًا من صفحة التصنيفات' : 'يوجد أكثر من تصنيف بهذا المسار'];

            return null;
        }

        $category = $lookups->categories[$ids[0]];

        if ($category->status === ArchiveStatus::Archived && $category->id !== $current) {
            $errors['category_path'] = ['التصنيف مؤرشف'];

            return null;
        }

        return $category;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, list<string>>  $errors
     */
    private function unit(array $row, string $column, CatalogLookups $lookups, ?int $current, array &$errors): ?Unit
    {
        $name = $row[$column] ?? '';

        if ($name === '') {
            return null;
        }

        $unit = $lookups->units[$this->fold($name)] ?? null;

        if (! $unit instanceof Unit) {
            $errors[$column] = ['الوحدة غير موجودة، أضفها أولًا من صفحة الوحدات'];

            return null;
        }

        if ($unit->status === ArchiveStatus::Archived && $unit->id !== $current) {
            $errors[$column] = ['الوحدة مؤرشفة'];

            return null;
        }

        return $unit;
    }

    /**
     * The tier pairs of the row by tier number (empty pairs left out), or null when the file has no tier column.
     *
     * @param  array<string, string>  $row
     * @return array<int, array{min_quantity: string, unit_price: string}>|null
     */
    private function tiers(array $row): ?array
    {
        $numbers = [];

        foreach (array_keys($row) as $key) {
            if (preg_match('/^tier_(\d+)_(min|price)$/', $key, $match) === 1) {
                $numbers[(int) $match[1]] = true;
            }
        }

        if ($numbers === []) {
            return null;
        }

        ksort($numbers);
        $tiers = [];

        foreach (array_keys($numbers) as $number) {
            $min = $row["tier_{$number}_min"] ?? '';
            $price = $row["tier_{$number}_price"] ?? '';

            if ($min !== '' || $price !== '') {
                $tiers[$number] = ['min_quantity' => $min, 'unit_price' => $price];
            }
        }

        return $tiers;
    }

    /**
     * The custom field map to send: the stored values, then each field column of the file on top of them.
     *
     * @param  array<string, string>  $row
     * @param  CategoryKind|null  $kind  null while the row's category is unknown (that is its error already)
     * @param  array<string, list<string>>  $errors
     * @return array<string, int|float|string|bool>
     */
    private function customFields(array $row, CatalogLookups $lookups, ?CategoryKind $kind, ?Product $existing, array &$errors): array
    {
        $values = $existing->custom_fields ?? [];

        foreach ($lookups->fields as $key => $definition) {
            $column = self::FIELD_PREFIX.$key;
            $cell = $row[$column] ?? null;

            if ($cell === null) {
                continue;
            }

            $applies = ! $kind instanceof CategoryKind || $definition->appliesTo($kind);

            if ($cell === '') {
                if ($applies) {
                    unset($values[$key]);
                }

                continue;
            }

            $value = $this->fieldValue($definition, $cell);

            if ($value === null) {
                $errors[$column] = ['الاختيار غير موجود في هذا الحقل'];
            } elseif (! $applies && ($values[$key] ?? null) !== $value) {
                $errors[$column] = ['هذا الحقل لا ينطبق على قسم هذا المنتج'];
            } else {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /** A select label → its option id (null when unknown); yes/no words → bool; whole numbers → int. */
    private function fieldValue(ProductFieldDefinition $definition, string $cell): int|float|string|bool|null
    {
        return match ($definition->type) {
            FieldType::Select => $definition->options->first(fn (ProductFieldOption $option): bool => $this->fold($option->label) === $this->fold($cell))?->id,
            FieldType::Boolean => $this->yesNo($cell) ?? $cell,
            FieldType::Number => is_numeric($cell) ? (filter_var($cell, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? (float) $cell) : $cell,
            FieldType::Text => $cell,
        };
    }

    /** نعم / لا (or true/false, 1/0, yes/no) → bool; anything else null. */
    private function yesNo(string $cell): ?bool
    {
        return match (true) {
            in_array($this->fold($cell), self::TRUE_WORDS, true) => true,
            in_array($this->fold($cell), self::FALSE_WORDS, true) => false,
            default => null,
        };
    }

    /**
     * @param  int|float|string|bool|null  $value
     */
    private function exportField(ProductFieldDefinition $definition, mixed $value): string|int|float|null
    {
        return match (true) {
            $value === null => null,
            $definition->type === FieldType::Select => $definition->options->firstWhere('id', $value)?->label,
            is_bool($value) => $value ? 'نعم' : 'لا',
            default => $value,
        };
    }

    /** The status as its value; an Arabic label ("نشط") is accepted too. Anything else fails the enum rule. */
    private function status(string $cell): string
    {
        $byLabel = array_flip(ProductStatus::toArray());

        return $byLabel[$cell] ?? $cell;
    }

    /**
     * The SKU the import writes: the file's units, each keeping the percentage its stored price was entered with when
     * the row repeats it (BR-PR10 / D95), then the SKU's other active units as they are (a file never drops a unit, D91).
     * Without a default named in the file, an existing SKU keeps its default; a new one takes its first row.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function withStoredUnits(ProductData $data, array $rows, ?Sku $sku, bool $defaultNamed): ProductData
    {
        $units = [];
        $named = [];

        foreach ($data->sku->units as $index => $unit) {
            $stored = $sku?->activeUnits->firstWhere('unit_id', $unit->unitId);
            $named[] = $unit->unitId;
            $units[] = new SkuUnitData(
                id: $stored?->id,
                unitId: $unit->unitId,
                conversionFactor: $unit->conversionFactor,
                isDefault: $defaultNamed ? $unit->isDefault : ($sku instanceof Sku ? $stored?->is_default === true : $index === 0),
                pricing: $stored instanceof SkuUnit ? $this->keepStoredPrices($unit->pricing, $rows[$index] ?? [], $stored) : $unit->pricing,
            );
        }

        foreach ($sku->activeUnits ?? [] as $stored) {
            if (! in_array($stored->unit_id, $named, true)) {
                $units[] = new SkuUnitData($stored->id, $stored->unit_id, $stored->conversion_factor, ! $defaultNamed && $stored->is_default, $this->storedPricing($stored));
            }
        }

        return new ProductData(
            name: $data->name,
            categoryId: $data->categoryId,
            sku: new SkuData($data->sku->code, $data->sku->baseUnitId, $this->oneDefault($units)),
            description: $data->description,
            customFields: $data->customFields,
            status: $data->status,
            openingStock: $data->openingStock,
        );
    }

    /**
     * Exactly one default: the first flagged, else the first unit.
     *
     * @param  non-empty-list<SkuUnitData>  $units
     * @return non-empty-list<SkuUnitData>
     */
    private function oneDefault(array $units): array
    {
        $default = array_key_first(array_filter($units, fn (SkuUnitData $unit): bool => $unit->isDefault)) ?? 0;

        return array_map(
            fn (SkuUnitData $unit, int $index): SkuUnitData => new SkuUnitData($unit->id, $unit->unitId, $unit->conversionFactor, $index === $default, $unit->pricing),
            $units,
            array_keys($units),
        );
    }

    /** A unit the file does not list, copied as stored. */
    private function storedPricing(SkuUnit $unit): SkuPricingData
    {
        return new SkuPricingData(
            basePrice: $unit->base_price,
            basePricePercent: $unit->base_price_percent,
            moq: $unit->moq,
            tiers: $this->prices->tiers($unit),
            coverPrice: $unit->cover_price,
        );
    }

    /**
     * BR-PR10 / D95: a row that repeats the stored base price and percentage, or a tier's stored price, keeps the
     * percentage that price was entered with instead of re-deriving the price from today's reference price.
     *
     * @param  array<string, string>  $row
     */
    private function keepStoredPrices(SkuPricingData $pricing, array $row, SkuUnit $stored): SkuPricingData
    {
        $basePrice = $pricing->basePrice;
        $cell = $row['base_price'] ?? '';

        if ($pricing->basePricePercent !== null && $pricing->basePricePercent === $stored->base_price_percent && is_numeric($cell) && Money::compare($cell, $stored->base_price) === 0) {
            $basePrice = $stored->base_price;
        }

        $tiers = $pricing->tiers === null ? null : array_map(function (TierData $tier) use ($stored): TierData {
            $match = $stored->tiers->firstWhere('min_quantity', $tier->minQuantity);

            return $match !== null && $match->discount_percent !== null && $tier->unitPrice !== null && Money::compare($tier->unitPrice, $match->unit_price) === 0
                ? new TierData($tier->minQuantity, $match->unit_price, $match->discount_percent)
                : $tier;
        }, $pricing->tiers);

        return new SkuPricingData($basePrice, $pricing->basePricePercent, $pricing->moq, $tiers, $pricing->coverPrice, $pricing->coverPriceGiven);
    }

    /**
     * Validation keys → the Arabic header of their column, so messages read "سعر الجملة مطلوب.".
     *
     * @param  array<int, list<int>>  $tierNumbers  per unit, the file's tier number of each sent tier, by position
     * @return array<string, string>
     */
    private function attributes(CatalogLookups $lookups, array $tierNumbers): array
    {
        $attributes = [];

        foreach (self::COLUMN_OF as $key => $column) {
            $attributes[$key] = $this->label($column, $lookups);
        }

        foreach (self::UNIT_COLUMN_OF as $key => $column) {
            $attributes["sku.units.*.{$key}"] = $this->label($column, $lookups);
        }

        foreach ($tierNumbers as $unit => $numbers) {
            foreach ($numbers as $index => $number) {
                $attributes["sku.units.{$unit}.tiers.{$index}.min_quantity"] = $this->label("tier_{$number}_min", $lookups);
                $attributes["sku.units.{$unit}.tiers.{$index}.unit_price"] = $this->label("tier_{$number}_price", $lookups);
            }
        }

        foreach ($lookups->fields as $key => $definition) {
            $attributes["custom_fields.{$key}"] = $definition->label;
        }

        return $attributes;
    }

    /**
     * A validation key → [the index of the unit whose row it is about (0 = the first row), the column].
     *
     * @param  array<int, list<int>>  $tierNumbers
     * @return array{0: int, 1: string}
     */
    private function columnOf(string $key, array $tierNumbers): array
    {
        if (str_starts_with($key, 'custom_fields.')) {
            return [0, self::FIELD_PREFIX.mb_substr($key, mb_strlen('custom_fields.'))];
        }

        if (preg_match('/^sku\.units\.(\d+)\.tiers\.(\d+)\.(min_quantity|unit_price|discount_percent)$/', $key, $match) === 1) {
            $number = $tierNumbers[(int) $match[1]][(int) $match[2]] ?? null;

            return [(int) $match[1], $number === null ? 'tiers' : ($match[3] === 'min_quantity' ? "tier_{$number}_min" : "tier_{$number}_price")];
        }

        if (preg_match('/^sku\.units\.(\d+)(?:\.([a-z_]+))?/', $key, $match) === 1) {
            return [(int) $match[1], self::UNIT_COLUMN_OF[$match[2] ?? 'unit_id'] ?? 'unit'];
        }

        return [0, self::COLUMN_OF[$key] ?? 'row'];
    }

    private function blankToNull(string $cell): ?string
    {
        return $cell === '' ? null : $cell;
    }

    private function number(?string $amount): int|float|null
    {
        if ($amount === null) {
            return null;
        }

        $value = (float) $amount;

        return floor($value) === $value ? (int) $value : $value;
    }

    private function fold(string $text): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', mb_trim($text)));
    }

    private function foldPath(string $path): string
    {
        return implode(self::PATH_SEPARATOR, array_map($this->fold(...), explode('>', $path)));
    }
}
