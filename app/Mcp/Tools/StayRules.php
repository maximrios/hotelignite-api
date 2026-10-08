<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Argumentos de estadía compartidos por check_availability y
 * search_availability. El rango de fechas (no en el pasado, salida posterior,
 * máximo de noches) lo valida `CheckAvailabilityService::parseStay`.
 */
final class StayRules
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'checkin' => ['required', 'date_format:Y-m-d'],
            'checkout' => ['required', 'date_format:Y-m-d'],
            'adults' => ['required', 'integer', 'min:1', 'max:10'],
            'children' => ['nullable', 'integer', 'min:0', 'max:6'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(JsonSchema $schema): array
    {
        return [
            'checkin' => $schema->string()->format('date')->required()->description('Fecha de llegada, YYYY-MM-DD.'),
            'checkout' => $schema->string()->format('date')->required()->description('Fecha de salida, YYYY-MM-DD.'),
            'adults' => $schema->integer()->min(1)->max(10)->required()->description('Cantidad de adultos.'),
            'children' => $schema->integer()->min(0)->max(6)->description('Cantidad de menores (default 0).'),
        ];
    }
}
