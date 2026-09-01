<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Hosts que la app acepta en el header `Host` (y en `X-Forwarded-Host`).
     *
     * Sin esto Laravel toma el host de la request tal como viene y lo usa para
     * armar toda URL absoluta: links de mail, `url()`, `route()`. Traefik enruta
     * por `Host`, pero no *borra* un `X-Forwarded-Host` entrante, y
     * `TrustProxies` lo tiene habilitado — así que un atacante mandando
     * `X-Forwarded-Host: evil.com` conseguía que la app generara links a su
     * dominio. (`PMS_INVITATION_URL` se salva porque sale de config, no de la
     * request.)
     *
     * El default es el dominio de `APP_URL` y sus subdominios. `TRUSTED_HOSTS`
     * permite sumar otros —un dominio de staging, un alias— separados por coma;
     * se agregan al de `APP_URL`, no lo reemplazan.
     *
     * El middleware es no-op en `local` y bajo tests (`shouldSpecifyTrustedHosts`),
     * así que en desarrollo no hay nada que configurar.
     *
     * @return array<int, string|null>
     */
    public function hosts()
    {
        $extra = array_values(array_filter(array_map(
            fn (string $host) => trim($host) === '' ? null : preg_quote(trim($host), '#'),
            explode(',', (string) env('TRUSTED_HOSTS', '')),
        )));

        return [
            $this->allSubdomainsOfApplicationUrl(),
            ...array_map(fn (string $host) => '^'.$host.'$', $extra),
        ];
    }
}
