<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Token de Cloudflare Turnstile. Se canjea una sola vez contra `siteverify`;
 * si Cloudflare no responde, se rechaza (el honeypot y el throttle no alcanzan
 * contra un bot que ya sabe que el captcha está caído).
 */
class Turnstile implements ValidationRule
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private readonly ?string $ip = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $result = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret' => (string) config('services.turnstile.secret_key'),
                'response' => (string) $value,
                'remoteip' => $this->ip,
            ]))->json();
        } catch (Throwable $e) {
            Log::warning('Turnstile: siteverify no respondió', ['error' => $e->getMessage()]);
            $result = null;
        }

        if (! is_array($result) || ($result['success'] ?? false) !== true) {
            $fail('No pudimos verificar que no seas un robot. Recargá la página y probá de nuevo.');
        }
    }
}
