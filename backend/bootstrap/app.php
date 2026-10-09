<?php

declare(strict_types=1);

use App\Modules\Tenancy\Console\TenantActivateCommand;
use App\Modules\Tenancy\Console\TenantCreateCommand;
use App\Modules\Tenancy\Console\TenantListCommand;
use App\Modules\Tenancy\Console\TenantSuspendCommand;
use App\Modules\Tenancy\Http\Middleware\EnsureSessionTenant;
use App\Modules\Tenancy\Http\Middleware\EnsureTenantActive;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        TenantCreateCommand::class,
        TenantSuspendCommand::class,
        TenantActivateCommand::class,
        TenantListCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA: cookie sessions for requests coming from tenant hosts (Tech §6).
        $middleware->statefulApi();

        // Pure API: guests get a 401 envelope, never a redirect to a login page.
        $middleware->redirectGuestsTo(fn (): ?string => null);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.active' => EnsureTenantActive::class,
            'session.tenant' => EnsureSessionTenant::class,
        ]);

        // Laravel re-sorts route middleware by priority and pulls `auth:*` ahead of unlisted middleware.
        // The tenant must be resolved before any guard runs, because tenant-scoped user lookups
        // (TenantScope) return nothing without a tenant in context.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ResolveTenant::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureTenantActive::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api/* error uses the envelope from 08-api-contract.md §0.
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (Throwable $e, Request $request): ?Illuminate\Http\JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof ApiException => ApiResponse::error($e->status, $e->errorCode, $e->getMessage(), $e->details, $e->items),

                $e instanceof ValidationException => ApiResponse::validation($e),

                $e instanceof AuthenticationException => ApiResponse::error(401, 'unauthenticated', 'يجب تسجيل الدخول'),

                $e instanceof AuthorizationException => ApiResponse::error(403, 'forbidden', 'غير مسموح بهذا الإجراء'),

                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(404, 'not_found', 'العنصر غير موجود'),

                $e instanceof ThrottleRequestsException => ApiResponse::error(429, 'rate_limited', 'محاولات كثيرة، حاول لاحقًا'),

                $e instanceof TokenMismatchException => ApiResponse::error(419, 'csrf_token_mismatch', 'انتهت صلاحية الجلسة، أعد تحميل الصفحة'),

                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getStatusCode(),
                    'http_error',
                    $e->getMessage() ?: 'حدث خطأ'
                ),

                default => ApiResponse::error(
                    500,
                    'server_error',
                    config('app.debug') ? $e->getMessage() : 'حدث خطأ غير متوقع',
                    config('app.debug') ? ['exception' => $e::class, 'file' => $e->getFile().':'.$e->getLine()] : null
                ),
            };
        });
    })->create();
