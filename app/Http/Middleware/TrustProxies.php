<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * Proxies en los que se confía para leer X-Forwarded-*.
     *
     * **No poner `'*'` acá.** Con `'*'` Symfony confía en toda la cadena de
     * `X-Forwarded-For` y termina devolviendo la entrada de más a la izquierda,
     * que es la que escribe el cliente. Cualquiera podía mandar un
     * `X-Forwarded-For` distinto en cada request y `$request->ip()` cambiaba con
     * él: eso deja sin efecto TODO throttle por IP, incluido el `throttle:login`
     * de 5/min que frena la fuerza bruta (verificado el 2026-08-30: 10 intentos
     * de login seguidos sin un solo 429).
     *
     * Confiando sólo en rangos privados, la cadena se recorre de derecha a
     * izquierda descartando proxies conocidos y se corta en la primera IP
     * pública — que es la que Traefik *agrega* después de lo que haya mandado el
     * cliente. El valor spoofeado queda a la izquierda y se ignora.
     *
     * Esto funciona porque a la app sólo se llega a través de Traefik: el
     * contenedor `api` no publica puertos (docker/docker-compose.prod.yml), así
     * que `REMOTE_ADDR` siempre es una IP privada de la red de Docker.
     *
     * Si algún día entra un CDN/WAF (Cloudflare) delante, hay que sumar sus
     * rangos vía `TRUSTED_PROXIES` — son públicos, y sin ellos la IP que vería
     * la app sería la del CDN.
     *
     * `127.0.0.1` queda deliberadamente afuera: nginx y php-fpm comparten
     * contenedor, pero `fastcgi_params` propaga `REMOTE_ADDR` como la IP del par
     * de nginx —Traefik—, nunca localhost. Incluirlo sólo agrandaría la
     * superficie.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct()
    {
        $configured = trim((string) env('TRUSTED_PROXIES', ''));

        $this->proxies = $configured === ''
            ? ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']
            : array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
