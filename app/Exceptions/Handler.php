<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Psr\Log\LogLevel;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<Throwable>, LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Un request sin autenticar a la API responde 401 con cuerpo JSON aunque
        // no haya mandado `Accept: application/json`. Sin esto Laravel intenta
        // redirigir a la ruta `login`, que no existe desde que se retiró el
        // scaffolding de Breeze — devolvía 500 en vez de 401.
        $this->renderable(function (AuthenticationException $e, Request $request) {
            if ($this->isApiRequest($request)) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    }

    /**
     * Toda excepción bajo un prefijo de API sale como JSON, mande el cliente el
     * `Accept` que mande.
     *
     * El default de Laravel decide el formato mirando `Accept`: un cliente B2B
     * que lo omita —o un browser que pegue a la API a mano— recibía la página
     * HTML de error de Symfony en vez de un cuerpo parseable, y con `APP_DEBUG`
     * accidentalmente en `true` esa página es un stack trace con fragmentos de
     * código y variables de entorno. Peor todavía: el mismo error salía en dos
     * formatos según quién preguntara, así que un integrador no podía escribir
     * un solo manejo de errores.
     *
     * Forzar el header antes de delegar en el handler base es lo que hace que
     * cada tipo de excepción conserve su forma canónica —422 con `errors` de
     * validación, 404 de model binding, 403 de Policy— en vez de aplanarlas
     * todas contra un formato inventado acá.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function render($request, Throwable $e)
    {
        if ($this->isApiRequest($request)) {
            $request->headers->set('Accept', 'application/json');
        }

        return parent::render($request, $e);
    }

    /**
     * Las tres superficies de API: Sanctum (`api/*`), la integración PMS
     * máquina-a-máquina (`pms/*`) y la API B2B por API key, que cuelga de
     * `api/client/v1` y por lo tanto ya entra por el primer patrón.
     *
     * `routes/web.php` está vacío a propósito (ver ese archivo), así que hoy
     * esto cubre todo lo ruteado; el chequeo queda igual para que agregar una
     * ruta web mañana no herede el forzado de JSON sin que nadie lo decida.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    private function isApiRequest($request): bool
    {
        return $request->is('api/*') || $request->is('pms/*');
    }
}
