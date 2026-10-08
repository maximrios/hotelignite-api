<?php

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
