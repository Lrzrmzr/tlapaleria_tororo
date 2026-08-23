<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // OJO: routes/api.php no es solo la app móvil — el login web (Login.vue,
        // vía useAuth/authService.js) también pasa por /api/login, /api/me,
        // /api/logout. Por eso el archivo completo se sigue registrando; el
        // toggle MOBILE_API_ENABLED vive dentro de routes/api.php, acotado
        // solo al grupo /mobile/* que sí es exclusivo de la app Capacitor.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Apagado por defecto (correcto mientras se corre local sin proxy delante).
        // Activar con TRUSTED_PROXIES en .env cuando haya Nginx/balanceador enfrente
        // (ver DEPLOY.md) — sin esto detrás de un proxy real, Laravel no detecta bien
        // HTTPS y rompe SESSION_SECURE_COOKIE y la generación de URLs.
        $trustedProxies = env('TRUSTED_PROXIES');
        if (!empty($trustedProxies)) {
            $middleware->trustProxies(at: $trustedProxies === '*' ? '*' : explode(',', $trustedProxies));
        }

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'check.branch' => \App\Http\Middleware\CheckBranchAccess::class,
        ]);

        // Excluir /api/login de la verificación CSRF
        $middleware->validateCsrfTokens(except: [
            'api/login',
        ]);

        // Asegurar que las rutas API siempre devuelvan JSON
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\ValidatePostSize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Manejar errores de autenticación en API
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'No autenticado. Por favor, proporcione un token de acceso válido.'
                ], 401);
            }
        });

        // $this->authorize() en los controllers Api/* debe devolver el mismo
        // sobre {success, message} que el resto de la API, no el genérico de Laravel.
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'No tienes permisos para realizar esta acción.',
                ], 403);
            }
        });
    })->create();
