<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;
use App\Policies\ActivityPolicy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // El login propio (AuthController::login) crea tokens con $user->createToken(),
        // que no pasa por las rutas HTTP de Passport (oauth/token, oauth/authorize, etc.).
        // Esas rutas quedaban activas por defecto sin usarse, con Password Grant habilitado
        // y sin el chequeo de cuenta desactivada que sí aplica el login propio. Se desactivan
        // aquí (antes de que arranque PassportServiceProvider::boot) porque nada las necesita.
        Passport::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Configurar expiración de tokens personales (los únicos que emite la app)
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));

        Gate::policy(Activity::class, ActivityPolicy::class);

        if ($this->app->environment('production') && config('app.debug')) {
            Log::critical('APP_DEBUG está activo en producción — expone stack traces y datos internos. Corrígelo en .env inmediatamente.');
        }
    }
}
