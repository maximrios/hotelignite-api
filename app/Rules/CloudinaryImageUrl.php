<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * La URL tiene que ser una imagen de Cloudinary. El logo de un client sale en
 * mails con nuestro dominio: aceptar cualquier URL sería dejar que un tercero
 * cambie lo que se ve (o lo rastree) después de que lo revisamos.
 */
class CloudinaryImageUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = (string) $value;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || $host !== 'res.cloudinary.com' || ! str_contains($url, '/image/upload/')) {
            $fail('El logo tiene que subirse desde el portal.');
        }
    }
}
