<?php

use App\Http\Middleware\EnforceAccountSecurity;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Media\Exceptions\MediaConfigurationException;
use App\Support\Media\Exceptions\UploadAdmissionException;
use App\Support\Media\Exceptions\UploadTransportException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->validateCsrfTokens(except: ['internal/tus/hooks']);

        $middleware->trustHosts(
            at: function (): array {
                $trustedHosts = config('app.trusted_hosts');

                if (! is_array($trustedHosts)) {
                    return [];
                }

                return array_values(array_filter($trustedHosts, is_string(...)));
            },
            subdomains: false,
        );
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(append: [
            HandleAppearance::class,
            EnforceAccountSecurity::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(
            fn (MediaConfigurationException $exception, Request $request) => response()->json([
                'message' => 'Media disk configuration is unavailable.',
            ], 503),
        );
        $exceptions->render(
            fn (UploadAdmissionException $exception, Request $request) => response()->json([
                'error' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->status),
        );
        $exceptions->render(
            fn (UploadTransportException $exception, Request $request) => response()->json([
                'error' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->status),
        );

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
