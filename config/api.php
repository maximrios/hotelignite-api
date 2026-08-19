<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API de clients externos (B2B)
    |--------------------------------------------------------------------------
    |
    | Configuración de la superficie B2B consumida con API keys de client.
    | Ver docs/api-clients-plan.md.
    |
    */

    // Rate limit por defecto (requests/minuto) para un client sin tier propio.
    'client_default_rpm' => (int) env('API_CLIENT_DEFAULT_RPM', 60),

    /*
    |--------------------------------------------------------------------------
    | Invitaciones (alta de asociados)
    |--------------------------------------------------------------------------
    |
    | Flujo por el que un client invita hoteleros por email. Ver
    | `.claude/skills/invitations.md`.
    |
    */

    // Vencimiento del token de invitación. 30 días: un padrón municipal tarda
    // semanas, y el costo de acortarlo son reenvíos (§5 de la skill).
    'invitation_expiration_days' => (int) env('API_INVITATION_EXPIRATION_DAYS', 30),

    // Plan por defecto de la Account que se crea al aceptar. Decisión tomada:
    // el default es `free` (§8) — por eso es seguro crear la cuenta al aceptar y
    // no al aprobar. Se resuelve por slug; si no existe, el alojamiento queda sin
    // plan (nullable) hasta que el staff lo encuadre.
    'default_plan_slug' => env('API_DEFAULT_PLAN_SLUG', 'free'),

    // Dónde aterriza el link del email: el PMS, no el CRM (§6). Se le concatena
    // `/{token}`. En prod apuntá a la ruta pública `/invitacion` del PMS.
    'pms_invitation_url' => env('PMS_INVITATION_URL', 'http://localhost:3001/invitacion'),

    // Rate limit anti-abuso de emisión de invitaciones (requests/minuto por
    // usuario de client). Alto a propósito: no es un tope de producto, es un
    // freno al pico de mails basura que nos quema la reputación de envío (§10).
    'invitation_rate_limit_per_minute' => (int) env('API_INVITATION_RATE_LIMIT', 30),

    /*
    |--------------------------------------------------------------------------
    | Catálogos geográficos del panel
    |--------------------------------------------------------------------------
    |
    | `states` es una tabla mundial: 4119 filas de ~246 países. Los selectores del
    | panel se acotan a un país para que la lista sea usable — Argentina son 24
    | provincias; el mundo entero no entra en un <select>.
    |
    | Vaciar esta clave desactiva el filtro y devuelve el catálogo completo. Es lo
    | que va a hacer falta el día que se opere fuera de Argentina, pero para
    | entonces el selector de provincia tiene que pasar a ser un buscador con
    | debounce (como el de ciudad), no un <select>.
    |
    */
    'catalog_country_iso' => env('API_CATALOG_COUNTRY_ISO', 'AR'),

];
