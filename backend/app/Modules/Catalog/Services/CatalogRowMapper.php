<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Data\CatalogLookups;
use App\Modules\Catalog\Data\ImportRowData;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\SkuStatus;
use App\Modules\Catalog\Exceptions\InvalidImportFile;
use App\Modules\Catalog\Exceptions\InvalidImportRow;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Services\PriceResolver;
use App\Support\ApiException;
use App\Support\ArabicText;
use App\Support\Money;
use Generator;
use Illuminate\Support\Facades\Validator;
use LogicException;

/**
 * The catalog file format, version 3 (V2 Task 14; version 2 was V2 Task 13, version 1 V2 Task 06): one row per unit a
 * SKU is sold in (D91), the SKUs of one product (its variants, BR-P14) on consecutive rows sharing a `product_key`.
 * Turns the tenant's catalog into rows for the export and file rows back into what CreateProduct / UpdateProduct take.
 *
 *  - Header ↔ keys: the key row (`product_key, sku_code, product_name, …`) is the first or second row (the export puts
 *    the Arabic labels above it); a file with only the Arabic label row is read through the labels. Unknown columns are
 *    ignored; a missing required column fails the whole file. The XLSX sheet is named `products_v3`; `products_v1` (its
 *    `selling_unit` column read as `unit`, each row a SKU in one unit) and `products_v2` files are still read.
 *  - Products (D95, V2 Task 14): consecutive rows with the same `product_key` are one product; an empty key is the row's
 *    SKU code, and a row with an empty key and the code of the row above stays with it, so a v1/v2 file (no key column)
 *    is one product per SKU as before. The product (name, category, status, description, custom fields) is read from
 *    its first row. A key or a code that comes back after another one is a duplicate (the whole product is skipped).
 *  - SKUs: inside a product, consecutive rows with the same code are one SKU; its first row holds its base unit, its
 *    stock, its variant cells (`variant_option_1/value_1`, `variant_option_2/value_2`) and `sku_status`, every row
 *    one unit: its name, factor, prices, MOQ, tiers and `unit_is_default` (نعم on at most one row). A unit the SKU has
 *    but the file does not list is kept as it is; with no default named, an existing SKU keeps its default and a new one
 *    takes its first row.
 *  - Variants (BR-P14, D92): option names and values are matched to the product's own folded (D32); a new name or
 *    value is added (values in first-seen order). A SKU of a product with options names a value of every option, or
 *    names none and keeps its stored ones. The import never removes a SKU, an option or a value the file does not list
 *    (same spirit as D91's units): the product's other SKUs are sent back as stored, in their order, new SKUs after them.
 *  - References are resolved by name: the category by its full path ("كتب خارجية > أولى إعدادي", D25; never
 *    created, archived only when the product already sits there), units by name (BR-U01), select fields by option
 *    label (BR-P08). A field of another section than the product's may only repeat the stored value (D69).
 *  - Every product is validated with StoreProductRequest's own rules through a Validator (the `sku` shape without
 *    options, `options` + `skus` with them), and the product data comes from its typed accessors, so the file obeys
 *    exactly what the API does. Each error names its row and column.
 *  - Optional columns left out of the file keep what the product has; an empty cell in a column that is there
 *    clears the value (description, reference price, %, a custom field) or, for MOQ, On Hand and the SKU status, keeps
 *    it. When any tier column is there, the tiers in a unit's row replace that unit's (D34).
 *  - A percentage wins over a price (BR-PR10), except that a row repeating the stored price and percentage keeps
 *    both, so exporting and importing the same file changes nothing (D95).
 *
 * @phpstan-type CellValue string|int|float|bool|null
 * @phpstan-type RowError array{row?: int, column: string, label: string, message: string}
 * @phpstan-type OptionPayload array{id: int|null, name: string, values: list<array{id: int|null, value: string, image_id: int|null}>}
 * @phpstan-type VariantPlan array{options: list<OptionPayload>, values: array<int, list<string>>, cells: array<int|string, array{0: int, 1: string}>}
 */
final readonly class CatalogRowMapper
{
    public const string SHEET_NAME = 'products_v3';

    /** The sheet names of the versions this mapper reads. */
    public const array READABLE_SHEETS = ['products_v1', 'products_v2', 'products_v3'];

    public const string FIELD_PREFIX = 'field:';

    public const string PATH_SEPARATOR = ' > ';

    public const int TIER_COLUMNS = 5;

    /** D92: a product has at most two options, one column pair each. */
    public const int MAX_OPTIONS = 2;

    /** The columns every file must have; `base_price` may be empty when `base_price_percent` is given. */
    public const array REQUIRED = ['sku_code', 'product_name', 'category_path', 'unit', 'base_unit', 'conversion_factor', 'base_price'];

    /** The fixed columns before the tiers, then after them `on_hand`, then one per custom field. */
    public const array LABELS = [
        'product_key' => 'مفتاح المنتج',
        'sku_code' => 'كود الصنف',
        'product_name' => 'اسم المنتج',
        'category_path' => 'التصنيف',
        'status' => 'الحالة',
        'description' => 'الوصف',
        'variant_option_1' => 'الخيار 1',
        'variant_value_1' => 'القيمة 1',
        'variant_option_2' => 'الخيار 2',
        'variant_value_2' => 'القيمة 2',
        'sku_status' => 'حالة الصنف',
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

    /** The variant cells of a SKU's first row (D92: two option / value pairs). */
    private const array VARIANT_COLUMNS = ['variant_option_1', 'variant_value_1', 'variant_option_2', 'variant_value_2'];

    /** Keys of format v1 read as today's. */
    private const array ALIASES = ['selling_unit' => 'unit'];

    /** Product-level validation keys → file columns (the product's first row). */
    private const array COLUMN_OF = [
        'name' => 'product_name',
        'category_id' => 'category_path',
        'description' => 'description',
        'status' => 'status',
        'opening_stock' => 'on_hand',
        'options' => 'variant_option_1',
        'skus' => 'sku_code',
        'base_price' => 'base_price',
        'tiers' => 'tiers',
    ];

    /** Validation keys under `sku.` / `skus.{i}.` → the columns of the SKU's first row. */
    private const array SKU_COLUMN_OF = [
        'id' => 'sku_code',
        'code' => 'sku_code',
        'base_unit_id' => 'base_unit',
        'units' => 'unit',
        'status' => 'sku_status',
        'option_values' => 'variant_value_1',
        'opening_stock' => 'on_hand',
    ];

    /** Validation keys under `…units.{i}.` → the columns of unit i's row. */
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

    /** Error codes whose details name the payload keys they are about. */
    private const array DETAILED_CODES = ['validation_failed', 'invalid_tiers', 'cover_price_required', 'duplicate_code'];

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
     * The rows of one product together (V2 Task 14): consecutive rows with the same product key (the SKU code when the
     * key is empty; a row with an empty key and the code of the row above stays with it). Keyed by the number of the
     * product's first row, with `duplicates` (row number => column => messages) when the file already had its key or
     * one of its codes: such a product is skipped whole.
     *
     * @param  iterable<int, array<string, string>>  $rows
     * @return Generator<int, array{rows: non-empty-array<int, array<string, string>>, duplicates: array<int, array<string, list<string>>>}>
     */
    public function products(iterable $rows): Generator
    {
        $group = [];
        $key = '';
        $code = '';
        $seenKeys = [];
        $seenCodes = [];

        foreach ($rows as $number => $row) {
            $rowCode = $row['sku_code'] ?? '';
            $rowKey = mb_trim($row['product_key'] ?? '');
            $continues = ($rowKey !== '' && $rowKey === $key) || ($rowKey === '' && $rowCode !== '' && $rowCode === $code);

            if ($group !== [] && ! $continues) {
                yield array_key_first($group) => $this->product($group, $seenKeys, $seenCodes);
                $group = [];
            }

            if ($group === []) {
                $key = $rowKey !== '' ? $rowKey : $rowCode;
            }

            $group[$number] = $row;
            $code = $rowCode;
        }

        if ($group !== []) {
            yield array_key_first($group) => $this->product($group, $seenKeys, $seenCodes);
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
     * Resolves and validates the rows of one product (row number => row, the product on the first; its SKUs on
     * consecutive rows by code). `$existing` is the product that has the rows' codes (an update).
     *
     * @param  non-empty-array<int, array<string, string>>  $rows
     *
     * @throws InvalidImportRow
     */
    public function map(array $rows, CatalogLookups $lookups, ?Product $existing = null): ImportRowData
    {
        $existing?->loadMissing(['category', 'options.values', 'skus.activeUnits.tiers', 'skus.optionValues']);
        $skus = array_values(iterator_to_array($this->skus($rows)));
        $first = array_key_first($rows);
        $head = $rows[$first];
        $errors = [];
        $productErrors = [];
        $category = $this->category($head['category_path'] ?? '', $lookups, $existing?->category_id, $productErrors);
        $sectionId = $category->section_id ?? $existing?->category->section_id;
        $customFields = $this->customFields($head, $lookups, $sectionId, $existing, $productErrors);
        $errors = $productErrors === [] ? [] : [$first => $productErrors];

        $stored = array_map(fn (array $skuRows): ?Sku => $this->storedSku($existing, $skuRows[array_key_first($skuRows)]['sku_code'] ?? ''), $skus);
        $variants = $this->hasVariants($skus, $existing);
        $plan = $variants ? $this->variantPlan($skus, $stored, $existing, $errors) : ['options' => [], 'values' => [], 'cells' => []];

        $entries = [];
        $fileRows = [];
        $tierNumbers = [];
        $defaults = [];
        $statuses = [];

        foreach ($skus as $index => $skuRows) {
            $fileRows[$index] = array_keys($skuRows);
            [$entries[$index], $tierNumbers[$index], $defaults[$index], $statuses[$index]] = $this->skuPayload($skuRows, $lookups, $stored[$index], $variants, $plan['values'][$index] ?? [], $errors);
        }

        if ($variants) {
            $this->checkVariants($skus, $stored, $statuses, $plan, $existing, $first, $errors);
        }

        $payload = [
            'name' => $head['product_name'] ?? '',
            'category_id' => $category?->id,
            'description' => array_key_exists('description', $head) ? $this->blankToNull($head['description']) : $existing?->description,
            'custom_fields' => $customFields,
            ...($variants ? ['options' => $plan['options'], 'skus' => $entries] : ['sku' => $entries[0] ?? []]),
            ...(($head['status'] ?? '') !== '' ? ['status' => $this->status($head['status'])] : []),
            ...(! $variants && ($head['on_hand'] ?? '') !== '' ? ['opening_stock' => $head['on_hand']] : []),
        ];

        $request = new StoreProductRequest($payload);
        $validator = Validator::make($payload, $request->rules(), [], $this->attributes($lookups, $tierNumbers, $variants));

        foreach ($validator->errors()->messages() as $key => $messages) {
            [$row, $column] = $this->cell($key, $fileRows, $tierNumbers, $plan['cells']);
            $errors[$row][$column] ??= array_values(array_filter($messages, is_string(...)));
        }

        foreach ($defaults as $index => $flagged) {
            if (count($flagged) > 1) {
                $errors[$fileRows[$index][$flagged[1]] ?? $first]['unit_is_default'] = ['وحدة افتراضية واحدة فقط لكل صنف'];
            }
        }

        if ($errors !== []) {
            throw InvalidImportRow::inRows($errors);
        }

        return $this->data($request, $skus, $stored, $defaults, $plan, $existing, $variants, $first);
    }

    /**
     * The row errors an exception stands for, each pointed at a row and a column of the file. `$rows` are, per SKU of
     * the product data (the index its error keys carry, `sku.` being 0), the numbers of its rows unit by unit; an error
     * about unit i is on its row, one about the SKU on its first row, any other on the product's first row.
     *
     * @param  array<int, list<int>>  $rows
     * @return list<RowError>
     */
    public function errors(ApiException $e, CatalogLookups $lookups, array $rows = [[0]]): array
    {
        $all = array_merge(...$rows);
        $first = $all === [] ? 0 : min($all);
        $errors = [];

        if ($e instanceof InvalidImportRow && $e->items !== null) {
            foreach ($e->items as $item) {
                $column = is_string($item['column'] ?? null) ? $item['column'] : 'row';
                $errors[] = ['row' => is_int($item['row'] ?? null) ? $item['row'] : $first, 'column' => $column, 'label' => $this->label($column, $lookups), 'message' => is_string($item['message'] ?? null) ? $item['message'] : ''];
            }

            return $errors;
        }

        if (in_array($e->errorCode, self::DETAILED_CODES, true) && $e->details !== null) {
            foreach ($e->details as $key => $messages) {
                [$row, $column] = $this->cell((string) $key, $rows);

                foreach (is_array($messages) ? $messages : [$messages] as $message) {
                    if (is_string($message)) {
                        $errors[] = ['row' => $row, 'column' => $column, 'label' => $this->label($column, $lookups), 'message' => $message];
                    }
                }
            }
        }

        if ($errors === []) {
            $column = self::COLUMN_OF_CODE[$e->errorCode] ?? 'row';
            $errors[] = ['row' => $first, 'column' => $column, 'label' => $this->label($column, $lookups), 'message' => $e->getMessage()];
        }

        return $errors;
    }

    /**
     * The export rows of a product: per SKU in its order (inactive variants included, BR-P14), one row per active unit,
     * the default first (D91), each with the product key (its first SKU's code), the product, the SKU's variant cells,
     * status, base unit and stock, the unit's prices and tiers, and the custom fields. Money is written as numbers so the
     * sheet can compute with it.
     *
     * @return list<array<string, CellValue>>
     */
    public function exportRows(Product $product, CatalogLookups $lookups): array
    {
        $key = $product->skus->first()?->code;
        $rows = [];

        foreach ($product->skus as $sku) {
            $units = $sku->activeUnits->all();

            foreach ($units === [] ? [null] : array_values($units) as $unit) {
                $rows[] = $this->exportRow($product, $key, $sku, $unit, $lookups);
            }
        }

        return $rows === [] ? [$this->exportRow($product, $key, null, null, $lookups)] : $rows;
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
     * One product group with the duplicates of the file in it: its key (when the file names it) or a code of it seen in
     * an earlier product, or a code that comes back after another code inside it.
     *
     * @param  non-empty-array<int, array<string, string>>  $group
     * @param  array<string, int>  $seenKeys  "k:{key}" => first row
     * @param  array<string, int>  $seenCodes  "c:{code}" => first row
     * @return array{rows: non-empty-array<int, array<string, string>>, duplicates: array<int, array<string, list<string>>>}
     */
    private function product(array $group, array &$seenKeys, array &$seenCodes): array
    {
        $first = array_key_first($group);
        $key = mb_trim($group[$first]['product_key'] ?? '');
        $duplicates = [];

        if ($key !== '') {
            $previous = $seenKeys["k:{$key}"] ?? null;

            if ($previous !== null) {
                $duplicates[$first] = ['product_key' => ["مفتاح المنتج مكرر في الملف (الصف {$previous})"]];
            }

            $seenKeys["k:{$key}"] ??= $first;
        }

        foreach ($this->skus($group) as $number => $rows) {
            $code = $rows[$number]['sku_code'] ?? '';

            if ($code === '') {
                continue;
            }

            $previous = $seenCodes["c:{$code}"] ?? null;

            if ($previous !== null) {
                $duplicates[$number]['sku_code'] = ["الكود مكرر في الملف (الصف {$previous})"];
            }

            $seenCodes["c:{$code}"] ??= $number;
        }

        return ['rows' => $group, 'duplicates' => $duplicates];
    }

    private function storedSku(?Product $existing, string $code): ?Sku
    {
        return $code === '' ? null : $existing?->skus->first(fn (Sku $sku): bool => $sku->code === $code);
    }

    /**
     * The `options` + `skus` shape is needed when the product has (or gets) options, or has more than one SKU.
     *
     * @param  list<non-empty-array<int, array<string, string>>>  $skus
     */
    private function hasVariants(array $skus, ?Product $existing): bool
    {
        if (count($skus) > 1 || $existing?->options->isNotEmpty() === true) {
            return true;
        }

        foreach ($skus as $rows) {
            $head = $rows[array_key_first($rows)];

            foreach (self::VARIANT_COLUMNS as $column) {
                if (mb_trim($head[$column] ?? '') !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * BR-P14: the product's options (stored ones first, by id, then names new to the product) with their values (stored
     * ones, then new ones in first-seen order), each file SKU's values in option order, and the cell that first named
     * each new option ("i") or value ("i.j") for its errors.
     *
     * @param  list<non-empty-array<int, array<string, string>>>  $skus
     * @param  list<Sku|null>  $stored
     * @param  array<int, array<string, list<string>>>  $errors
     * @return VariantPlan
     */
    private function variantPlan(array $skus, array $stored, ?Product $existing, array &$errors): array
    {
        $heads = [];
        $lists = [];
        $cells = [];

        foreach ($existing->options ?? [] as $option) {
            $heads[] = ['id' => $option->id, 'name' => $option->name];
            $lists[] = array_values(array_map(fn (ProductOptionValue $value): array => ['id' => $value->id, 'value' => $value->value, 'image_id' => $value->image_id], $option->values->all()));
        }

        $named = [];

        foreach ($skus as $index => $rows) {
            $number = array_key_first($rows);
            $named[$index] = [];

            for ($n = 1; $n <= self::MAX_OPTIONS; $n++) {
                $name = $this->squish($rows[$number]["variant_option_{$n}"] ?? '');
                $value = $this->squish($rows[$number]["variant_value_{$n}"] ?? '');

                if ($name === '' && $value === '') {
                    continue;
                }

                if ($name === '' || $value === '') {
                    $errors[$number][$name === '' ? "variant_option_{$n}" : "variant_value_{$n}"] ??= [$name === '' ? 'اكتب اسم الخيار' : 'اكتب قيمة الخيار'];

                    continue;
                }

                $optionIndex = $this->matching(array_column($heads, 'name'), $name);

                if ($optionIndex === null && count($heads) >= self::MAX_OPTIONS) {
                    $errors[$number]["variant_option_{$n}"] ??= ['للمنتج خياران على الأكثر'];

                    continue;
                }

                if ($optionIndex === null) {
                    $optionIndex = count($heads);
                    $heads[] = ['id' => null, 'name' => $name];
                    $lists[] = [];
                    $cells["{$optionIndex}"] = [$number, "variant_option_{$n}"];
                }

                if (array_key_exists($optionIndex, $named[$index])) {
                    $errors[$number]["variant_option_{$n}"] ??= ['الخيار مكرر في هذا الصنف'];

                    continue;
                }

                $list = $lists[$optionIndex] ?? [];
                $valueIndex = $this->matching(array_column($list, 'value'), $value);

                if ($valueIndex === null) {
                    $valueIndex = count($list);
                    $lists[$optionIndex] = [...$list, ['id' => null, 'value' => $value, 'image_id' => null]];
                    $cells["{$optionIndex}.{$valueIndex}"] = [$number, "variant_value_{$n}"];
                }

                $named[$index][$optionIndex] = $lists[$optionIndex][$valueIndex]['value'] ?? $value;
            }
        }

        $options = [];

        foreach ($heads as $optionIndex => $head) {
            $options[] = [...$head, 'values' => $lists[$optionIndex] ?? []];
        }

        $values = [];

        foreach ($skus as $index => $rows) {
            $sku = $stored[$index] ?? null;
            $given = $named[$index] === [] && $sku instanceof Sku ? array_filter($this->storedValues($sku, $options), is_string(...)) : $named[$index];
            $values[$index] = [];

            foreach ($options as $optionIndex => $option) {
                $values[$index][] = $given[$optionIndex] ?? '';

                if (! isset($given[$optionIndex])) {
                    $errors[array_key_first($rows)]['variant_value_'.min($optionIndex + 1, self::MAX_OPTIONS)] ??= ["اكتب قيمة «{$option['name']}» لهذا الصنف"];
                }
            }
        }

        return ['options' => $options, 'values' => $values, 'cells' => $cells];
    }

    /**
     * BR-P14 checks the request rules leave to the form (ProductPayloadRules::after): without options one SKU only; each
     * combination once; at least one SKU active; and the product's SKUs the file does not list must have a value of
     * every option and a unit to be sent back as they are.
     *
     * @param  list<non-empty-array<int, array<string, string>>>  $skus
     * @param  list<Sku|null>  $stored
     * @param  array<int, string>  $statuses
     * @param  VariantPlan  $plan
     * @param  array<int, array<string, list<string>>>  $errors
     */
    private function checkVariants(array $skus, array $stored, array $statuses, array $plan, ?Product $existing, int $first, array &$errors): void
    {
        $listed = array_values(array_filter(array_map(fn (?Sku $sku): ?int => $sku?->id, $stored)));
        $others = $existing instanceof Product ? array_values($existing->skus->reject(fn (Sku $sku): bool => in_array($sku->id, $listed, true))->all()) : [];

        if ($plan['options'] === [] && count($skus) + count($others) > 1) {
            $errors[isset($skus[1]) ? array_key_first($skus[1]) : $first]['variant_option_1'] ??= ['المنتج بأكثر من صنف يحتاج خيارًا وقيمة لكل صنف'];

            return;
        }

        $combinations = [];
        $active = false;

        foreach ($skus as $index => $rows) {
            $active = $active || ($statuses[$index] ?? '') === SkuStatus::Active->value;
            $combination = $this->combination($plan['values'][$index] ?? []);

            if ($combination !== null && in_array($combination, $combinations, true)) {
                $errors[array_key_first($rows)]['variant_value_1'] ??= ['هذه التركيبة مكررة في المنتج'];
            }

            $combinations[] = $combination;
        }

        foreach ($others as $sku) {
            $values = $this->storedValues($sku, $plan['options']);
            $missing = array_search(null, $values, true);
            $active = $active || $sku->isActive();

            if (is_int($missing)) {
                $errors[$first]['variant_option_'.min($missing + 1, self::MAX_OPTIONS)] ??= ["اذكر في الملف كل أصناف المنتج بقيمة «{$plan['options'][$missing]['name']}»"];
            } elseif (in_array($this->combination(array_values(array_filter($values, is_string(...)))), $combinations, true)) {
                $errors[$first]['variant_value_1'] ??= ["تركيبة الصنف {$sku->code} مكررة في المنتج"];
            }

            if ($sku->activeUnits->isEmpty()) {
                $errors[$first]['row'] ??= ["الصنف {$sku->code} ليس له وحدة بيع نشطة، اذكره في الملف بوحداته"];
            }
        }

        if (! $active) {
            $errors[$first]['sku_status'] ??= ['صنف واحد نشط على الأقل'];
        }
    }

    /**
     * The value of each option (by its id) a stored SKU has, null where it has none.
     *
     * @param  list<OptionPayload>  $options
     * @return list<string|null>
     */
    private function storedValues(Sku $sku, array $options): array
    {
        return array_map(
            fn (array $option): ?string => $option['id'] === null ? null : $sku->optionValues->first(fn (ProductOptionValue $value): bool => $value->product_option_id === $option['id'])?->value,
            $options,
        );
    }

    /**
     * The folded key of a combination, null while a value is missing (that is an error of its own).
     *
     * @param  list<string>  $values
     */
    private function combination(array $values): ?string
    {
        return in_array('', $values, true) ? null : implode('|', array_map(ArabicText::normalize(...), $values));
    }

    /**
     * The position of the text among the given ones, compared folded (D32), or null.
     *
     * @param  list<string>  $texts
     */
    private function matching(array $texts, string $text): ?int
    {
        $folded = ArabicText::normalize($text);

        foreach ($texts as $index => $candidate) {
            if (ArabicText::normalize($candidate) === $folded) {
                return $index;
            }
        }

        return null;
    }

    /**
     * One SKU as the product request takes it, from its rows: [payload, tier numbers per unit, the indexes of the units
     * flagged default, the status value]. Variant SKUs also carry their id, status, option values and opening stock.
     *
     * @param  non-empty-array<int, array<string, string>>  $rows
     * @param  list<string>  $values
     * @param  array<int, array<string, list<string>>>  $errors
     * @return array{0: array<string, mixed>, 1: list<list<int>>, 2: list<int>, 3: string}
     */
    private function skuPayload(array $rows, CatalogLookups $lookups, ?Sku $sku, bool $variants, array $values, array &$errors): array
    {
        $numbers = array_keys($rows);
        $head = $rows[$numbers[0]];
        $headErrors = [];
        $baseUnit = $this->unit($head, 'base_unit', $lookups, $sku?->base_unit_id, $headErrors);
        $status = $this->skuStatus($head['sku_status'] ?? '', $sku);

        if (! $variants && $status !== SkuStatus::Active->value) {
            $headErrors['sku_status'] = ['صنف المنتج بدون خيارات نشط دائمًا'];
        }

        $this->addErrors($errors, $numbers[0], $headErrors);

        $units = [];
        $tierNumbers = [];
        $sold = [];

        foreach (array_values($rows) as $index => $row) {
            $unitErrors = [];
            $unit = $this->unitPayload($row, $lookups, $sku, $unitErrors);

            if ($unit['unit_id'] !== null && in_array($unit['unit_id'], $sold, true)) {
                $unitErrors['unit'] ??= ['الوحدة مكررة في هذا الصنف'];
            }

            $sold[] = $unit['unit_id'];
            $units[] = $unit;
            $tierNumbers[] = array_keys($this->tiers($row) ?? []);
            $this->addErrors($errors, $numbers[$index], $unitErrors);
        }

        $entry = ['code' => $head['sku_code'] ?? '', 'base_unit_id' => $baseUnit?->id, 'units' => $units];

        if ($variants) {
            $entry = [
                'id' => $sku?->id,
                ...$entry,
                'status' => $status,
                'option_values' => $values,
                ...(($head['on_hand'] ?? '') !== '' ? ['opening_stock' => $head['on_hand']] : []),
            ];
        }

        $defaults = array_keys(array_filter($units, fn (array $unit): bool => $unit['is_default'] === true));

        return [$entry, $tierNumbers, $defaults, $status];
    }

    /**
     * The SKU status cell as a value (its Arabic label is accepted); empty keeps the stored status, active for a new SKU.
     */
    private function skuStatus(string $cell, ?Sku $sku): string
    {
        if ($cell === '') {
            return $sku->status->value ?? SkuStatus::Active->value;
        }

        return array_flip(SkuStatus::toArray())[$cell] ?? $cell;
    }

    /**
     * @param  array<int, array<string, list<string>>>  $errors
     * @param  array<string, list<string>>  $new
     */
    private function addErrors(array &$errors, int $row, array $new): void
    {
        foreach ($new as $column => $messages) {
            $errors[$row][$column] ??= $messages;
        }
    }

    /**
     * The validated product as the actions take it: the file's SKUs with their stored units kept (D91) and, for a product
     * with variants, the product's SKUs the file does not list sent back as stored, everything in the product's order
     * with new SKUs last; with the file rows and the On Hand of each SKU.
     *
     * @param  list<non-empty-array<int, array<string, string>>>  $skus
     * @param  list<Sku|null>  $stored
     * @param  array<int, list<int>>  $defaults
     * @param  VariantPlan  $plan
     */
    private function data(StoreProductRequest $request, array $skus, array $stored, array $defaults, array $plan, ?Product $existing, bool $variants, int $first): ImportRowData
    {
        $data = ProductData::fromRequest($request);
        $fromFile = [];

        foreach ($data->skus as $index => $sku) {
            $rows = $skus[$index] ?? [];
            $head = reset($rows) ?: [];
            $onHand = ($head['on_hand'] ?? '') !== '' ? ($variants ? $request->skuInt('opening_stock', 0, "skus.{$index}") : $request->openingStock()) : null;
            $fromFile[$index] = [$this->withStoredUnits($sku, array_values($rows), $stored[$index] ?? null, ($defaults[$index] ?? []) !== []), array_keys($rows), $onHand];
        }

        $ordered = [];
        $placed = [];

        foreach ($variants ? $existing->skus ?? [] : [] as $sku) {
            $index = array_search($sku->id, array_map(fn (?Sku $listed): ?int => $listed?->id, $stored), true);

            if (is_int($index) && isset($fromFile[$index])) {
                $ordered[] = $fromFile[$index];
                $placed[] = $index;
            } else {
                $ordered[] = [$this->copied($sku, $plan['options']), [$first], null];
            }
        }

        foreach ($fromFile as $index => $entry) {
            if (! in_array($index, $placed, true)) {
                $ordered[] = $entry;
            }
        }

        $final = [];
        $rows = [];
        $onHand = [];

        foreach ($ordered as $position => [$sku, $numbers, $stock]) {
            $final[] = $variants ? $this->withPath($sku, "skus.{$position}") : $sku;
            $rows[] = $numbers;

            if ($stock !== null) {
                $onHand[$position] = $stock;
            }
        }

        return new ImportRowData(
            product: new ProductData(
                name: $data->name,
                categoryId: $data->categoryId,
                skus: $final,
                options: $data->options,
                description: $data->description,
                customFields: $data->customFields,
                status: $data->status,
            ),
            status: $request->status(),
            onHand: $onHand,
            rows: $rows,
        );
    }

    /**
     * A SKU of the product the file does not list, sent back as stored: its active units, status and option values.
     *
     * @param  list<OptionPayload>  $options
     */
    private function copied(Sku $sku, array $options): SkuData
    {
        $units = array_values(array_map(fn (SkuUnit $unit): SkuUnitData => new SkuUnitData($unit->id, $unit->unit_id, $unit->conversion_factor, $unit->is_default, $this->storedPricing($unit)), $sku->activeUnits->all()));

        if ($units === []) {
            throw new LogicException('checkVariants() refuses a product whose unlisted SKU has no active unit.');
        }

        return new SkuData(
            code: $sku->code,
            baseUnitId: $sku->base_unit_id,
            units: $this->oneDefault($units),
            id: $sku->id,
            status: $sku->status,
            optionValues: array_values(array_filter($this->storedValues($sku, $options), is_string(...))),
        );
    }

    private function withPath(SkuData $sku, string $path): SkuData
    {
        return new SkuData($sku->code, $sku->baseUnitId, $sku->units, $sku->id, $sku->status, $sku->optionValues, $sku->openingStock, $path);
    }

    /**
     * @param  array<string, CellValue>  $row
     * @return array<string, CellValue>
     */
    private function variantCells(Product $product, ?Sku $sku, array $row): array
    {
        $options = array_values($product->options->all());

        for ($n = 1; $n <= self::MAX_OPTIONS; $n++) {
            $option = $options[$n - 1] ?? null;
            $row["variant_option_{$n}"] = $option?->name;
            $row["variant_value_{$n}"] = $option instanceof ProductOption
                ? $sku?->optionValues->first(fn (ProductOptionValue $value): bool => $value->product_option_id === $option->id)?->value
                : null;
        }

        $row['sku_status'] = $options === [] ? null : $sku?->status->label();

        return $row;
    }

    /**
     * @return array<string, CellValue>
     */
    private function exportRow(Product $product, ?string $key, ?Sku $sku, ?SkuUnit $unit, CatalogLookups $lookups): array
    {
        $row = $this->variantCells($product, $sku, [
            'product_key' => $key,
            'sku_code' => $sku?->code,
            'product_name' => $product->name,
            'category_path' => $lookups->paths[$product->category_id] ?? '',
            'status' => $product->status->label(),
            'description' => $product->description,
        ]);

        $row = [
            ...$row,
            'base_unit' => $sku?->baseUnit->name,
            'unit' => $unit?->unit->name,
            'unit_is_default' => $unit instanceof SkuUnit ? $unit->is_default ? 'نعم' : 'لا' : (null),
            'conversion_factor' => $unit?->conversion_factor,
            'cover_price' => $this->number($unit?->cover_price),
            'base_price' => $this->number($unit?->base_price),
            'base_price_percent' => $this->number($unit?->base_price_percent),
            'moq' => $unit?->moq,
        ];

        $tier = 0;

        foreach ($unit instanceof SkuUnit ? $unit->tiers->sortBy('min_quantity') : [] as $stored) {
            $tier++;
            $row["tier_{$tier}_min"] = $stored->min_quantity;
            $row["tier_{$tier}_price"] = $this->number($stored->unit_price);
        }

        $row['on_hand'] = $sku->inventory->on_hand ?? 0;

        foreach ($lookups->fields as $fieldKey => $definition) {
            $row[self::FIELD_PREFIX.$fieldKey] = $this->exportField($definition, $product->custom_fields[$fieldKey] ?? null);
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
     * @param  int|null  $sectionId  the section of the row's category; null while it is unknown (that is its error already)
     * @param  array<string, list<string>>  $errors
     * @return array<string, int|float|string|bool>
     */
    private function customFields(array $row, CatalogLookups $lookups, ?int $sectionId, ?Product $existing, array &$errors): array
    {
        $values = $existing->custom_fields ?? [];

        foreach ($lookups->fields as $key => $definition) {
            $column = self::FIELD_PREFIX.$key;
            $cell = $row[$column] ?? null;

            if ($cell === null) {
                continue;
            }

            $applies = $sectionId === null || $definition->appliesTo($sectionId);

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
     * A SKU the import writes: the file's units, each keeping the percentage its stored price was entered with when
     * the row repeats it (BR-PR10 / D95), then the SKU's other active units as they are (a file never drops a unit, D91).
     * Without a default named in the file, an existing SKU keeps its default; a new one takes its first row.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function withStoredUnits(SkuData $data, array $rows, ?Sku $sku, bool $defaultNamed): SkuData
    {
        $units = [];
        $named = [];

        foreach ($data->units as $index => $unit) {
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

        return new SkuData($data->code, $data->baseUnitId, $this->oneDefault($units), $data->id, $data->status, $data->optionValues, $data->openingStock, $data->path);
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
     * @param  array<int, list<list<int>>>  $tierNumbers  per SKU and unit, the file's tier number of each sent tier, by position
     * @return array<string, string>
     */
    private function attributes(CatalogLookups $lookups, array $tierNumbers, bool $variants): array
    {
        $attributes = [];
        $prefix = $variants ? 'skus.*' : 'sku';

        foreach (self::COLUMN_OF as $key => $column) {
            $attributes[$key] = $this->label($column, $lookups);
        }

        foreach (self::SKU_COLUMN_OF as $key => $column) {
            $attributes["{$prefix}.{$key}"] = $this->label($column, $lookups);
        }

        $attributes["{$prefix}.option_values.*"] = $this->label('variant_value_1', $lookups);

        foreach (self::UNIT_COLUMN_OF as $key => $column) {
            $attributes["{$prefix}.units.*.{$key}"] = $this->label($column, $lookups);
        }

        foreach ($tierNumbers as $sku => $units) {
            $skuPrefix = $variants ? "skus.{$sku}" : 'sku';

            foreach ($units as $unit => $numbers) {
                foreach ($numbers as $index => $number) {
                    $attributes["{$skuPrefix}.units.{$unit}.tiers.{$index}.min_quantity"] = $this->label("tier_{$number}_min", $lookups);
                    $attributes["{$skuPrefix}.units.{$unit}.tiers.{$index}.unit_price"] = $this->label("tier_{$number}_price", $lookups);
                }
            }
        }

        for ($n = 1; $n <= self::MAX_OPTIONS; $n++) {
            $index = $n - 1;
            $attributes["options.{$index}.name"] = $this->label("variant_option_{$n}", $lookups);
            $attributes["options.{$index}.values"] = $this->label("variant_value_{$n}", $lookups);
            $attributes["options.{$index}.values.*.value"] = $this->label("variant_value_{$n}", $lookups);
        }

        foreach ($lookups->fields as $key => $definition) {
            $attributes["custom_fields.{$key}"] = $definition->label;
        }

        return $attributes;
    }

    /**
     * A validation or action error key → [its row, its column]. `$skuRows` are, per SKU index of the key (`sku.` = 0),
     * the rows of its units; `$optionCells` the cells that named each option ("i") and value ("i.j").
     *
     * @param  array<int, list<int>>  $skuRows
     * @param  array<int, list<list<int>>>  $tierNumbers
     * @param  array<int|string, array{0: int, 1: string}>  $optionCells
     * @return array{0: int, 1: string}
     */
    private function cell(string $key, array $skuRows, array $tierNumbers = [], array $optionCells = []): array
    {
        $all = array_merge(...$skuRows);
        $first = $all === [] ? 0 : min($all);

        if (preg_match('/^(?:sku|skus\.(\d+))\.(.+)$/', $key, $match) === 1) {
            $sku = (int) $match[1];
            $rows = $skuRows[$sku] ?? [];

            return $this->skuCell($match[2], $rows === [] ? [$first] : $rows, $tierNumbers[$sku] ?? []);
        }

        if (str_starts_with($key, 'custom_fields.')) {
            return [$first, self::FIELD_PREFIX.mb_substr($key, mb_strlen('custom_fields.'))];
        }

        if (preg_match('/^options\.(\d+)(?:\.values\.(\d+))?/', $key, $match) === 1) {
            return $optionCells[isset($match[2]) ? "{$match[1]}.{$match[2]}" : $match[1]] ?? $optionCells[$match[1]] ?? [$first, 'variant_option_'.min((int) $match[1] + 1, self::MAX_OPTIONS)];
        }

        return [$first, self::COLUMN_OF[$key] ?? 'row'];
    }

    /**
     * A key under one SKU (`code`, `units.1.base_price`, `units.0.tiers.2.unit_price`, `option_values.1`) → its cell.
     *
     * @param  non-empty-list<int>  $rows
     * @param  list<list<int>>  $tierNumbers
     * @return array{0: int, 1: string}
     */
    private function skuCell(string $key, array $rows, array $tierNumbers): array
    {
        if (preg_match('/^units\.(\d+)\.tiers\.(\d+)\.(min_quantity|unit_price|discount_percent)$/', $key, $match) === 1) {
            $number = $tierNumbers[(int) $match[1]][(int) $match[2]] ?? null;

            return [$rows[(int) $match[1]] ?? $rows[0], $number === null ? 'tiers' : ($match[3] === 'min_quantity' ? "tier_{$number}_min" : "tier_{$number}_price")];
        }

        if (preg_match('/^units\.(\d+)(?:\.([a-z_]+))?/', $key, $match) === 1) {
            return [$rows[(int) $match[1]] ?? $rows[0], self::UNIT_COLUMN_OF[$match[2] ?? 'unit_id'] ?? 'unit'];
        }

        if (preg_match('/^option_values\.(\d+)$/', $key, $match) === 1) {
            return [$rows[0], 'variant_value_'.min((int) $match[1] + 1, self::MAX_OPTIONS)];
        }

        return [$rows[0], self::SKU_COLUMN_OF[explode('.', $key)[0]] ?? 'row'];
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
        return mb_strtolower($this->squish($text));
    }

    /** Trimmed, every run of white space one space (case kept): how option names and values are stored. */
    private function squish(string $text): string
    {
        return mb_trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function foldPath(string $path): string
    {
        return implode(self::PATH_SEPARATOR, array_map($this->fold(...), explode('>', $path)));
    }
}
