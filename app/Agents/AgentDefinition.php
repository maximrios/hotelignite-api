<?php

namespace App\Agents;

/**
 * Un tipo de agente que HotelIgnite ofrece. El "qué" del agente vive acá y en su
 * servidor MCP (tools + instructions); Colmexa pone el motor y los canales.
 */
final readonly class AgentDefinition
{
    /**
     * @param  list<string>  $capabilities  rótulos para el prompt de Colmexa
     * @param  list<string>  $abilities  de la key que se le emite
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $description,
        public string $audience,
        public string $feature,
        public string $plan,
        public ?string $mcpPath,
        public array $capabilities = [],
        public array $abilities = [],
    ) {}

    /** Si ya se puede activar. Los que no tienen MCP todavía son "próximamente". */
    public function isAvailable(): bool
    {
        return $this->mcpPath !== null;
    }
}
