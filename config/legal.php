<?php

/*
 * Documentos legales que acepta el usuario del PMS. Cambiar la versión de uno
 * obliga a volver a aceptarlo en el próximo ingreso (pantalla de aceptación).
 *
 * Los textos viven en el PMS (`pms/app/legal/*`) y son BORRADORES: tienen que
 * pasar por un abogado (rol de encargado de tratamiento, AAIP, validez del
 * consentimiento, umbral de anonimización, proveedores de IA). Ver `hoteleria`.
 */
return [
    'documents' => [
        'terms' => [
            'title' => 'Términos y condiciones',
            'version' => '2026-10-28',
            'path' => '/legal/terminos',
        ],
        'privacy' => [
            'title' => 'Política de privacidad',
            'version' => '2026-10-28',
            'path' => '/legal/privacidad',
        ],
        // Autorización de uso de datos operativos agregados y anónimos para
        // estadísticas del destino. Nunca datos personales de huéspedes.
        'data_usage' => [
            'title' => 'Autorización de uso de datos agregados',
            'version' => '2026-10-28',
            'path' => '/legal/datos',
        ],
    ],
];
