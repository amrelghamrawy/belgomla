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

/** A request on the default test tenant's storefront API. */
function storeRequest(string $path = '/api/store/products'): Illuminate\Http\Request
{
    return Illuminate\Http\Request::create('http://alnoor.gomal.test'.$path);
}

/** A request on the default test tenant's dashboard API. */
function dashboardRequest(string $path = '/api/dashboard/products'): Illuminate\Http\Request
{
    return Illuminate\Http\Request::create('http://alnoor.gomal.test'.$path);
}

/**
 * A resource exactly as it is sent over HTTP: nested resources and when() fields resolved.
 *
 * @return array<string, mixed>
 */
function rendered(Illuminate\Http\Resources\Json\JsonResource $resource, Illuminate\Http\Request $request): array
{
    $decoded = json_decode((string) $resource->toResponse($request)->getContent(), true);

    if (! is_array($decoded) || ! is_array($decoded['data'] ?? null)) {
        throw new RuntimeException('The resource did not render a data object.');
    }

    $data = [];

    foreach ($decoded['data'] as $key => $value) {
        $data[(string) $key] = $value;
    }

    return $data;
}

/**
 * Runs a callback with another database session (`pgsql_session_a|b`, config/database.php) as the default
 * connection, pointed at this process's test database. That session is outside RefreshDatabase's
 * transaction: it cannot see the test's uncommitted rows and what it writes is committed, so clean up.
 *
 * @template TReturn
 *
 * @param  callable(Illuminate\Database\Connection): TReturn  $callback
 * @return TReturn
 */
function onSession(string $connection, callable $callback): mixed
{
    config(["database.connections.{$connection}.database" => config()->string('database.connections.pgsql.database')]);

    $previous = Illuminate\Support\Facades\DB::getDefaultConnection();
    Illuminate\Support\Facades\DB::setDefaultConnection($connection);

    try {
        return $callback(Illuminate\Support\Facades\DB::connection($connection));
    } finally {
        Illuminate\Support\Facades\DB::setDefaultConnection($previous);
    }
}
