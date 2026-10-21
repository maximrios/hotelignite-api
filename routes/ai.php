<?php

use App\Mcp\Servers\AccommodationServer;
use App\Mcp\Servers\TravelerServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| Servidores MCP
|--------------------------------------------------------------------------
|
| Cargado por laravel/mcp. El MCP del viajero se autentica con la API key del
| client (misma tenencia que /api/client/v1) y exige la ability `mcp:read`:
| la key que se usa acá es dedicada, para poder revocarla sin tocar la del BFF.
| Ver docs/mcp-traveler-plan.md.
|
*/

Mcp::web('/mcp/traveler', TravelerServer::class)
    ->middleware(['auth.client', 'throttle:client', 'client.ability:mcp:read'])
    ->name('mcp.traveler');

// MCP del alojamiento: un agente contesta en nombre de UN alojamiento, el de la
// API key (`ak_live_…`, emitida desde el PMS). Ver docs/accommodation-mcp-plan.md.
Mcp::web('/mcp/accommodation', AccommodationServer::class)
    ->middleware(['auth.accommodation', 'throttle:accommodation', 'accommodation.ability:mcp:read'])
    ->name('mcp.accommodation');
