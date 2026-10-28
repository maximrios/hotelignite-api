<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Captcha del formulario de leads de la landing. Sin secreto → no se exige.
    'turnstile' => [
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    // Cobro de los planes del PMS con suscripciones (preapproval) de Mercado Pago.
    // `subscription_back_url`: adonde vuelve el hotelero desde Mercado Pago (la
    // pantalla de facturación del PMS; https en producción).
    'mercadopago' => [
        'access_token' => env('MP_ACCESS_TOKEN'),
        'webhook_secret' => env('MP_WEBHOOK_SECRET'),
        'subscription_back_url' => env('MP_SUBSCRIPTION_BACK_URL', 'https://pms.hotelignite.com/facturacion'),
        // Sólo pruebas: el comprador de prueba que paga en lugar del email del hotelero.
        'test_payer_email' => env('MP_TEST_PAYER_EMAIL'),
    ],

];
