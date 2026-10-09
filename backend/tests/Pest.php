<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Http, Console and Unit tests all run as a tenant-aware Laravel test case with
| RefreshDatabase (see TenantTestCase). Storage is always faked.
|
*/

pest()->extend(TenantTestCase::class)
    ->beforeEach(function (): void {
        Storage::fake('public');
    })
    ->in('Http', 'Console', 'Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Namespaces of every module for a given sub-namespace, e.g. moduleNamespaces('Http\Controllers')
 * => ['App\Modules\Tenancy\Http\Controllers', ...]. Used by the architecture tests.
 *
 * @return list<string>
 */
function moduleNamespaces(string $suffix = ''): array
{
    $namespaces = [];

    foreach (glob(__DIR__.'/../app/Modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
        // Only namespaces that exist on disk, so a module without e.g. Middleware is not expected to have any.
        if ($suffix !== '' && ! is_dir($dir.'/'.str_replace('\\', '/', $suffix))) {
            continue;
        }

        $namespaces[] = mb_rtrim('App\\Modules\\'.basename($dir).'\\'.$suffix, '\\');
    }

    return $namespaces;
}

/**
 * Fully-qualified class names of every Eloquent model in every module.
 *
 * @return list<class-string<Illuminate\Database\Eloquent\Model>>
 */
function moduleModels(): array
{
    $classes = [];

    foreach (glob(__DIR__.'/../app/Modules/*/Models/*.php') ?: [] as $file) {
        $module = basename(dirname($file, 2));
        $class = 'App\\Modules\\'.$module.'\\Models\\'.basename($file, '.php');

        if (is_subclass_of($class, Illuminate\Database\Eloquent\Model::class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}
