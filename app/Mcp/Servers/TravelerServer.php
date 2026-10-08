<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CheckAvailabilityTool;
use App\Mcp\Tools\GetAccommodationDetailsTool;
use App\Mcp\Tools\ListDestinationsTool;
use App\Mcp\Tools\SearchAccommodationsTool;
use App\Mcp\Tools\SearchAvailabilityTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * MCP del viajero (Grupo A de docs/mcp-design.md). Solo lectura: buscar
 * alojamientos y consultar disponibilidad del catálogo del client autenticado.
 * Ver docs/mcp-traveler-plan.md.
 */
#[Name('HotelIgnite Viajero')]
#[Version('0.1.0')]
#[Instructions('Herramientas para ayudar a un viajero a encontrar alojamiento en el catálogo de la agencia. Flujo típico: list_destinations para resolver el lugar, search_availability si hay fechas (o search_accommodations si no), get_accommodation_details para preguntas puntuales y check_availability para un alojamiento concreto. Precios y disponibilidad salen solo de las herramientas. Datos con source "mock" son de prueba.')]
class TravelerServer extends Server
{
    protected array $tools = [
        ListDestinationsTool::class,
        SearchAccommodationsTool::class,
        GetAccommodationDetailsTool::class,
        CheckAvailabilityTool::class,
        SearchAvailabilityTool::class,
    ];
}
