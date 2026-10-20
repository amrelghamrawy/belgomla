<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Exceptions\InvalidImportFile;
use DateInterval;
use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Comment\TextRun;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Options as CsvReaderOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxReaderOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Options as CsvWriterOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use ValueError;

/**
 * The only class that touches OpenSpout (V2 Task 06; an arch test keeps it so). Streams XLSX and CSV both ways
 * with low memory, whatever the size of the catalog.
 *
 * Writing: a header of `key => label`. XLSX gets the Arabic labels in bold on a right-to-left sheet and, when
 * `withKeys`, the machine keys in a second, hidden-looking row (2px high, grey) so a file comes back with its
 * columns named exactly; CSV (UTF-8 with a BOM, so Excel shows Arabic) gets the keys only, or the labels when
 * there are no keys to write. Rows are maps by header key; a missing key is an empty cell.
 *
 * Reading: the first sheet, every non-empty row as `row number (1-based, as the spreadsheet shows it) => cells as
 * text`. Numbers come back without exponent or trailing zeros (90.0 → "90", 7.5 → "7.5"), dates as Y-m-d. A file
 * OpenSpout cannot read is `422 validation_failed` on `file` (InvalidImportFile), so nothing else sees OpenSpout.
 *
 * @phpstan-type Cell string|int|float|bool|null
 */
final readonly class Spreadsheet
{
    private const float KEY_ROW_HEIGHT = 1.5;

    /**
     * @param  array<string, string>  $headers  key => label, in column order
     * @param  iterable<array<string, Cell>>  $rows
     */
    public function write(string $path, SpreadsheetFormat $format, array $headers, iterable $rows, string $sheetName = 'Sheet1', bool $withKeys = false): void
    {
        $keys = array_keys($headers);
        $writer = $format === SpreadsheetFormat::Xlsx ? new XlsxWriter : new CsvWriter(new CsvWriterOptions(SHOULD_ADD_BOM: true));
        $writer->openToFile($path);

        try {
            if ($writer instanceof XlsxWriter) {
                $sheet = $writer->getCurrentSheet();
                $sheet->setName($sheetName);
                $sheet->setSheetView(new SheetView(rightToLeft: true, freezeRow: $withKeys ? 3 : 2));
                $writer->addRow(Row::fromValuesWithStyle(array_values($headers), new Style(fontBold: true)));

                if ($withKeys) {
                    $writer->addRow(Row::fromValuesWithStyle($keys, new Style(fontSize: 6, fontColor: 'A0A0A0'), self::KEY_ROW_HEIGHT));
                }
            } else {
                $writer->addRow(Row::fromValues($withKeys ? $keys : array_values($headers)));
            }

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues(array_map(fn (string $key): string|int|float|bool|null => $row[$key] ?? null, $keys)));
            }
        } finally {
            $writer->close();
        }
    }

    /**
     * @return Generator<int, list<string>>
     */
    public function read(string $path, SpreadsheetFormat $format): Generator
    {
        $reader = $this->open($path, $format);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $cells = array_map($this->text(...), array_values($row->toArray()));

                    if (implode('', $cells) !== '') {
                        yield $number => $cells;
                    }
                }

                break;
            }
        } catch (OpenSpoutException|ValueError) {
            throw InvalidImportFile::unreadable();
        } finally {
            $reader->close();
        }
    }

    /** The name of the first sheet; null for a CSV, which has none. */
    public function sheetName(string $path, SpreadsheetFormat $format): ?string
    {
        if ($format === SpreadsheetFormat::Csv) {
            return null;
        }

        $reader = $this->open($path, $format);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                return $sheet->getName();
            }

            return null;
        } finally {
            $reader->close();
        }
    }

    private function open(string $path, SpreadsheetFormat $format): XlsxReader|CsvReader
    {
        $reader = $format === SpreadsheetFormat::Xlsx
            ? new XlsxReader(new XlsxReaderOptions(SHOULD_PRESERVE_EMPTY_ROWS: true))
            : new CsvReader(new CsvReaderOptions(SHOULD_PRESERVE_EMPTY_ROWS: true));

        try {
            $reader->open($path);
        } catch (OpenSpoutException) {
            throw InvalidImportFile::unreadable();
        }

        return $reader;
    }

    /**
     * @param  Cell|DateTimeInterface|DateInterval|array<TextRun>  $value
     */
    private function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $this->clean($value),
            is_int($value) => (string) $value,
            is_float($value) => $this->number($value),
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_array($value) => $this->clean(implode('', array_map(fn (TextRun $run): string => $run->text, $value))),
            default => '',
        };
    }

    /** Trims, and drops a BOM that a CSV saved by another tool may carry on its first cell. */
    private function clean(string $value): string
    {
        return mb_trim(str_replace("\u{FEFF}", '', $value));
    }

    private function number(float $value): string
    {
        if (floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return mb_rtrim(mb_rtrim(sprintf('%.10F', $value), '0'), '.');
    }
}
