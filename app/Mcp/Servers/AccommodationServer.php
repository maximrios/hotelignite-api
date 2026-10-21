<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Accommodation\CheckAvailabilityTool;
use App\Mcp\Tools\Accommodation\CreateInquiryTool;
use App\Mcp\Tools\Accommodation\GetAccommodationInfoTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * MCP del alojamiento: un agente externo (Colmexa por WhatsApp, el widget de la
 * web del hotel, etc.) contesta a huéspedes en nombre de UN alojamiento, el de
 * la API key. Ver docs/accommodation-mcp-plan.md.
 */
#[Name('HotelIgnite Alojamiento')]
#[Version('0.1.0')]
#[Instructions('Herramientas para atender a los huéspedes de un único alojamiento, el de la credencial. Contestá sólo sobre este alojamiento: no recomiendes otros. Usá get_accommodation_info para preguntas sobre el lugar (servicios, habitaciones, políticas, dirección) y check_availability cuando haya fechas y cantidad de huéspedes. Precios y disponibilidad salen sólo de las herramientas; si el estado es "unknown", el huésped quiere reservar o un dato no figura, no lo inventes: ofrecé dejarle la consulta al alojamiento con create_inquiry (confirmando antes nombre, teléfono, fechas y personas). Datos con source "mock" son de prueba.')]
class AccommodationServer extends Server
{
    protected array $tools = [
        GetAccommodationInfoTool::class,
        CheckAvailabilityTool::class,
        CreateInquiryTool::class,
    ];
}
