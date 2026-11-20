<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Where the catalog import and export keep their files (V2 Task 06): the private `local` disk, one folder per
 * tenant. An uploaded file waits under `imports/{tenant}/{token}.{ext}` (its original name beside it in
 * `{token}.json`) for `catalog.import_token_hours` after its preview; a queued export is written to
 * `exports/{tenant}/{uuid}.{ext}`, or leaves `{uuid}.failed` when its job fails. Old files of the tenant are pruned
 * whenever a new one is stored, so nothing needs a scheduler.
 *
 * @phpstan-type StoredFile array{token: string, path: string, format: SpreadsheetFormat, name: string}
 */
final readonly class CatalogFiles
{
    public const string DISK = 'local';

    public function __construct(private TenantContext $tenant) {}

    /**
     * @return StoredFile
     */
    public function storeUpload(UploadedFile $file): array
    {
        $this->prune('imports');

        $format = SpreadsheetFormat::fromExtension($file->getClientOriginalExtension()) ?? SpreadsheetFormat::Csv;
        $token = (string) Str::uuid();
        $name = Str::limit($file->getClientOriginalName(), 250, '');

        $this->disk()->putFileAs($this->folder('imports'), $file, "{$token}.{$format->value}");
        $this->disk()->put($this->folder('imports')."/{$token}.json", json_encode(['name' => $name], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->stamp($this->folder('imports')."/{$token}.{$format->value}", $this->folder('imports')."/{$token}.json");

        return $this->upload($token) ?? throw new RuntimeException('The uploaded catalog file was not stored.');
    }

    /**
     * The uploaded file of a preview token; null when there is none or, unless `$evenExpired`, it is too old.
     *
     * @return StoredFile|null
     */
    public function upload(string $token, bool $evenExpired = false): ?array
    {
        foreach (SpreadsheetFormat::cases() as $format) {
            $path = $this->folder('imports')."/{$token}.{$format->value}";

            if (! $this->disk()->exists($path)) {
                continue;
            }

            if (! $evenExpired && $this->expired($path)) {
                return null;
            }

            $meta = json_decode((string) $this->disk()->get($this->folder('imports')."/{$token}.json"), true);

            return [
                'token' => $token,
                'path' => $this->disk()->path($path),
                'format' => $format,
                'name' => is_array($meta) && is_string($meta['name'] ?? null) ? $meta['name'] : "products.{$format->value}",
            ];
        }

        return null;
    }

    public function deleteUpload(string $token): void
    {
        $this->disk()->delete(array_map(
            fn (string $extension): string => $this->folder('imports')."/{$token}.{$extension}",
            [...array_map(fn (SpreadsheetFormat $format): string => $format->value, SpreadsheetFormat::cases()), 'json'],
        ));
    }

    /**
     * The absolute path a new export is written to, `{uuid}.{ext}.part` until finishExport() renames it, so a
     * download never gets a half-written file. Its folder exists and old exports of the tenant are pruned.
     */
    public function newExport(string $uuid, SpreadsheetFormat $format): string
    {
        $this->prune('exports');
        $this->disk()->makeDirectory($this->folder('exports'));

        return $this->disk()->path($this->folder('exports')."/{$uuid}.{$format->value}.part");
    }

    public function finishExport(string $uuid, SpreadsheetFormat $format): void
    {
        $path = $this->folder('exports')."/{$uuid}.{$format->value}";
        $this->disk()->move("{$path}.part", $path);
        $this->stamp($path);
    }

    /**
     * A finished export: its absolute path and format; `failed` when its job failed; `pending` while it runs (or
     * when the uuid is unknown or its file expired: the signed link that carries the uuid tells those apart).
     *
     * @return array{path: string, format: SpreadsheetFormat}|'failed'|'pending'
     */
    public function export(string $uuid): array|string
    {
        foreach (SpreadsheetFormat::cases() as $format) {
            $path = $this->folder('exports')."/{$uuid}.{$format->value}";

            if ($this->disk()->exists($path) && ! $this->expired($path)) {
                return ['path' => $this->disk()->path($path), 'format' => $format];
            }
        }

        return $this->disk()->exists($this->folder('exports')."/{$uuid}.failed") ? 'failed' : 'pending';
    }

    public function markExportFailed(string $uuid): void
    {
        $this->disk()->put($this->folder('exports')."/{$uuid}.failed", '');
        $this->stamp($this->folder('exports')."/{$uuid}.failed");
    }

    private function prune(string $kind): void
    {
        foreach ($this->disk()->files($this->folder($kind)) as $path) {
            if ($this->expired($path)) {
                $this->disk()->delete($path);
            }
        }
    }

    /** Files are dated by the app clock (not the disk's), so their age reads the same as every other date. */
    private function stamp(string ...$paths): void
    {
        foreach ($paths as $path) {
            touch($this->disk()->path($path), now()->getTimestamp());
        }
    }

    private function expired(string $path): bool
    {
        return $this->disk()->lastModified($path) < now()->subHours(config()->integer('catalog.import_token_hours'))->getTimestamp();
    }

    private function folder(string $kind): string
    {
        return "{$kind}/{$this->tenant->id()}";
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
