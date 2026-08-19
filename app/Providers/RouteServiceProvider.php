<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('api')
                ->prefix('pms')
                ->group(base_path('routes/pms.php'));

            // API B2B para consumidores externos (portales, agencias, gobiernos)
            // autenticados con API key de client. Auth y rate limit se aplican
            // dentro del archivo (auth.client + throttle:client). Ver
            // docs/api-clients-plan.md.
            Route::prefix('api/client/v1')
                ->group(base_path('routes/client-api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip())->response(function () {
                return response()->json([
                    'message' => 'You have exceeded. Too Many Attempts.',
                ], 429);
            });
        });

        // Clients B2B: límite por tier del client (columna rate_limit_per_minute,
        // o default de config). El middleware `auth.client` deja el Client
        // actuante en los attributes de la request antes de que corra el throttle.
        // ThrottleRequests agrega los headers X-RateLimit-* automáticamente.
        RateLimiter::for('client', function (Request $request) {
            $client = $request->attributes->get('api_client');
            $limit = $client ? $client->rateLimit() : (int) config('api.client_default_rpm', 60);
            $key = $client ? "client:{$client->id}" : $request->ip();

            return Limit::perMinute($limit)->by($key)->response(function () {
                return response()->json([
                    'message' => 'Límite de peticiones excedido. Demasiadas requests.',
                ], 429);
            });
        });

        // Emisión de invitaciones: freno anti-abuso por usuario de client. Alto
        // a propósito (§10 de la skill) — un lote de 500 emails es UNA request.
        RateLimiter::for('invitations', function (Request $request) {
            $limit = (int) config('api.invitation_rate_limit_per_minute', 30);

            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip())->response(function () {
                return response()->json([
                    'message' => 'Límite de invitaciones excedido. Probá de nuevo en un minuto.',
                ], 429);
            });
        });

        // Endpoints públicos de invitación (por token). Freno por IP: el token es
        // de 64 chars aleatorios (fuerza bruta inviable), esto es defensa extra.
        RateLimiter::for('invitations-public', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip())->response(function () {
                return response()->json([
                    'message' => 'Demasiadas peticiones. Probá de nuevo en un minuto.',
                ], 429);
            });
        });

        // Login: límite estricto anti-fuerza-bruta, por email + IP.
        RateLimiter::for('login', function (Request $request) {
            $key = strtolower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key)->response(function () {
                return response()->json([
                    'message' => 'Demasiados intentos de login. Probá de nuevo en un minuto.',
                ], 429);
            });
        });
    }
}
