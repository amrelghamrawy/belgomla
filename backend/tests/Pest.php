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

/**
 * A provider payload from tests/Fixtures/<provider>/<name>.json.
 *
 * @return array<array-key, mixed>
 */
function fixture(string $path): array
{
    $decoded = json_decode((string) file_get_contents(__DIR__.'/Fixtures/'.$path.'.json'), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("Fixture {$path} is not a JSON object.");
    }

    return $decoded;
}

/** Points Messaging at a fake Hypersender account; pair with Http::fake() and Http::preventStrayRequests(). */
function useHypersender(): void
{
    config([
        'messaging.driver' => 'hypersender',
        'messaging.hypersender.base_url' => 'https://app.hypersender.com/api',
        'messaging.hypersender.token' => 'wa-token',
        'messaging.hypersender.sms_token' => 'sms-token',
        'messaging.hypersender.whatsapp_instance' => 'wa-instance',
        'messaging.hypersender.sms_instance' => 'sms-instance',
        'messaging.hypersender.safe_send' => true,
    ]);
}

/** Runs a job's handle() through the container, like a worker would, even while Queue::fake() is on. */
function runJob(App\Modules\Messaging\Jobs\SendMessage|App\Modules\Messaging\Jobs\PollMessageStatus|App\Modules\Messaging\Jobs\ProcessProviderWebhook $job): void
{
    app()->call($job->handle(...));
}

/**
 * The text of the newest outbound message, any tenant. Messaging clears it once delivered, so use it with
 * Queue::fake().
 */
function lastMessageText(): string
{
    $message = App\Modules\Messaging\Models\OutboundMessage::withoutTenantScope()->latest('id')->first();

    if ($message?->body === null) {
        throw new RuntimeException('No outbound message with a body; is Queue::fake() on?');
    }

    return $message->body;
}

/** The 6-digit code of the newest OTP message (BR-CU07). */
function lastOtpCode(): string
{
    if (preg_match('/\b(\d{6})\b/', lastMessageText(), $match) !== 1) {
        throw new RuntimeException('The newest message carries no 6-digit code.');
    }

    return $match[1];
}

/** The token at the end of the link in the newest invitation message (BR-CU08). */
function lastInvitationToken(): string
{
    if (preg_match('#/invite/([A-Za-z0-9]{40})#', lastMessageText(), $match) !== 1) {
        throw new RuntimeException('The newest message carries no invitation link.');
    }

    return $match[1];
}

/**
 * The hand-written OpenAPI spec (backend/openapi/openapi.yaml), parsed.
 *
 * @return array<string, mixed>
 */
function openApiSpec(): array
{
    $spec = Symfony\Component\Yaml\Yaml::parseFile(__DIR__.'/../openapi/openapi.yaml');

    if (! is_array($spec)) {
        throw new RuntimeException('openapi.yaml is not a map.');
    }

    /** @var array<string, mixed> $spec */
    return $spec;
}

/**
 * A component schema of the OpenAPI spec, e.g. openApiSchema('TenantSettings').
 *
 * @return array<string, mixed>
 */
function openApiSchema(string $name): array
{
    $schema = data_get(openApiSpec(), "components.schemas.{$name}");

    if (! is_array($schema)) {
        throw new RuntimeException("No schema {$name} in openapi.yaml.");
    }

    /** @var array<string, mixed> $schema */
    return $schema;
}

/**
 * Every error code the backend throws: `new self(403, 'code', ...)` in exceptions, ApiException helpers
 * (`forbidden('code', ...)`), `ApiResponse::error(401, 'code', ...)` in bootstrap/app.php, and the customer
 * statuses that CustomerNotActive uses as codes.
 *
 * @return list<string>
 */
function emittedErrorCodes(): array
{
    $files = [
        ...glob(__DIR__.'/../app/Modules/*/Exceptions/*.php') ?: [],
        ...glob(__DIR__.'/../app/Support/*.php') ?: [],
        ...glob(__DIR__.'/../app/Support/Http/Middleware/*.php') ?: [],
        __DIR__.'/../bootstrap/app.php',
    ];

    $codes = array_map(
        fn (App\Modules\Customers\Enums\CustomerStatus $status): string => $status->value,
        array_filter(App\Modules\Customers\Enums\CustomerStatus::cases(), fn (App\Modules\Customers\Enums\CustomerStatus $status): bool => $status !== App\Modules\Customers\Enums\CustomerStatus::Active),
    );

    foreach ($files as $file) {
        preg_match_all("/(?:(?:\b\d{3}|error\(\s*\\\$e->getStatusCode\(\)),\s*|(?:forbidden|conflict|unprocessable|unavailable)\()'([a-z_]+)'/", (string) file_get_contents($file), $matches);
        $codes = [...$codes, ...$matches[1]];
    }

    $codes = array_values(array_unique($codes));
    sort($codes);

    return $codes;
}

/** A started in-memory session, for actions that log a customer in. */
function arraySession(): Illuminate\Session\Store
{
    $session = new Illuminate\Session\Store('test', new Illuminate\Session\ArraySessionHandler(10));
    $session->start();

    return $session;
}
