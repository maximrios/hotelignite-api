<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Capa de servicios AI (MCP del viajero)
    |--------------------------------------------------------------------------
    |
    | Configuración de `App\AI`, la capa que consumen los servidores MCP. Ver
    | docs/mcp-traveler-plan.md.
    |
    */

    // Idiomas en los que las herramientas responden. El idioma de la request
    // sale de `Accept-Language`; lo que no esté acá cae a `default_language`.
    'languages' => ['es'],

    'default_language' => 'es',

    // Completa con datos simulados lo que falta (inventario, precios, tipos de
    // habitación). Todo dato simulado sale con `source: "mock"`. En producción se
    // ignora aunque esté en true. Ver docs/mcp-mocks-todo.md.
    'mock_missing_data' => (bool) env('AI_MOCK_MISSING_DATA', false),

    // Estadía máxima que acepta una consulta de disponibilidad (noches).
    'max_stay_nights' => (int) env('AI_MAX_STAY_NIGHTS', 30),

];
