<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El throttle por IP tiene que resistir un `X-Forwarded-For` falsificado.
 *
 * Regresión del 2026-08-30: `TrustProxies::$proxies` estaba en `'*'`, con lo que
 * Laravel confiaba en toda la cadena de X-Forwarded-For y devolvía la entrada de
 * más a la izquierda — la que escribe el cliente. Rotando ese header se hacían
 * 10 intentos de login seguidos sin disparar el `throttle:login` de 5/min: la
 * protección anti-fuerza-bruta quedaba anulada por un header.
 */
class RateLimitSpoofingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear($this->limiterKey());
    }

    private function limiterKey(): string
    {
        // Misma forma que el limiter `login` de RouteServiceProvider: email|ip.
        return sha1('spoof-probe@test.local|127.0.0.1');
    }

    #[Test]
    public function un_x_forwarded_for_falsificado_no_evade_el_throttle_de_login(): void
    {
        $credenciales = ['email' => 'spoof-probe@test.local', 'password' => 'incorrecta'];

        // Los primeros 5 pasan el limiter y mueren en las credenciales (401).
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['HTTP_X_FORWARDED_FOR' => "203.0.113.{$i}"])
                ->postJson('/api/auth/login', $credenciales)
                ->assertStatus(401);
        }

        // El sexto tiene que chocar contra el limiter aunque venga con otra IP
        // declarada. Si esto devuelve 401, el header volvió a ser creído.
        $this->withServerVariables(['HTTP_X_FORWARDED_FOR' => '203.0.113.99'])
            ->postJson('/api/auth/login', $credenciales)
            ->assertStatus(429);
    }
}
