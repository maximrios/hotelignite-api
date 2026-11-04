<?php

namespace App\Agents;

use App\Models\AccommodationApiKey;

/**
 * Los agentes de HotelIgnite. Es código y no tabla a propósito: cada uno es un
 * servidor MCP con sus tools, y no existe sin un deploy. Ver docs/ai-agents-plan.md.
 *
 * Los que todavía no tienen MCP se listan igual, como "próximamente": el
 * hotelero ve hacia dónde va el producto y qué plan lo va a incluir.
 */
final class AgentCatalog
{
    /**
     * @return array<string, AgentDefinition>
     */
    public static function all(): array
    {
        $agents = [
            new AgentDefinition(
                slug: 'recepcionista',
                name: 'Recepcionista',
                description: 'Contesta a los huéspedes por WhatsApp y por la web: disponibilidad, precios, servicios y políticas. Lo que no puede resolver te lo deja como consulta.',
                audience: 'Huéspedes',
                feature: AccommodationApiKey::FEATURE,
                plan: 'Pro',
                mcpPath: '/mcp/accommodation',
                capabilities: ['disponibilidad', 'precios', 'ficha del alojamiento', 'consultas'],
                abilities: AccommodationApiKey::ABILITIES,
            ),
            new AgentDefinition(
                slug: 'asistente-pms',
                name: 'Asistente del equipo',
                description: 'Dentro del PMS: consulta reservas y tarifas, y con tu confirmación abre o cierra fechas y cambia precios.',
                audience: 'Tu equipo',
                feature: 'ai_pms_assistant',
                plan: 'Pro',
                mcpPath: null,
            ),
            new AgentDefinition(
                slug: 'revenue',
                name: 'Revenue',
                description: 'Mira tu ocupación y el ritmo de reservas todos los días y te propone ajustes de precio por WhatsApp.',
                audience: 'Dueño o gerente',
                feature: 'ai_revenue',
                plan: 'Pro',
                mcpPath: null,
            ),
            new AgentDefinition(
                slug: 'conserje',
                name: 'Conserje',
                description: 'Acompaña al huésped con reserva: indicaciones antes de llegar, pedidos durante la estadía y encuesta al irse.',
                audience: 'Huéspedes con reserva',
                feature: 'ai_concierge',
                plan: 'Pro',
                mcpPath: null,
            ),
        ];

        $bySlug = [];

        foreach ($agents as $agent) {
            $bySlug[$agent->slug] = $agent;
        }

        return $bySlug;
    }

    public static function find(string $slug): ?AgentDefinition
    {
        return self::all()[$slug] ?? null;
    }
}
