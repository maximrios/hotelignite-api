<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Models\Accommodation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta el padrón del client como CSV. La tenencia sale del token
 * (`scopeVisibleTo` recorta al pivote `active`); no pagina —es un solo archivo
 * con todo el padrón filtrado, no una página.
 *
 * Expone SOLO campos de catálogo. A diferencia de `Admin\AccommodationResource`
 * —que devuelve `bank_data`, `tax_identification` y comentarios internos del
 * hotelero, y hoy no filtra por rol—, este export nunca los toca. Es la razón
 * de no reusar aquel Resource. Ver el caveat en `clients/CLAUDE.md`.
 */
class AccommodationExportController extends BaseController
{
    /**
     * Columnas exportables: clave pública => [encabezado, extractor]. El orden
     * de este array es el orden de las columnas en el archivo. Ninguna toca
     * datos internos de la cuenta del hotelero (ver nota de clase).
     */
    private function columns(): array
    {
        return [
            'name' => ['Nombre', fn (Accommodation $a) => $a->name],
            'slug' => ['Slug', fn (Accommodation $a) => $a->slug],
            'city' => ['Ciudad', fn (Accommodation $a) => $a->city?->name],
            'state' => ['Provincia', fn (Accommodation $a) => $a->state?->name],
            'type' => ['Tipo', fn (Accommodation $a) => $a->type?->name],
            'enabled' => ['Estado', fn (Accommodation $a) => $a->enabled ? 'Habilitado' : 'Deshabilitado'],
        ];
    }

    public function index(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'enabled' => 'nullable|boolean',
            'columns' => 'nullable|string',
            'headers' => 'nullable|boolean',
        ]);

        $available = $this->columns();

        // Selección de columnas: intersección con la whitelist, en orden canónico
        // (no en el orden en que las mandó el cliente). Vacío o desconocido => todas.
        $requested = filled($validated['columns'] ?? null)
            ? explode(',', $validated['columns'])
            : array_keys($available);
        $selected = array_values(array_filter(
            array_keys($available),
            fn ($key) => in_array($key, $requested, true),
        ));
        if (empty($selected)) {
            $selected = array_keys($available);
        }

        // Separador: `;` (Excel-ES, default) o `,`. No se valida con `in:` porque
        // la coma es el delimitador de reglas de Laravel; se sanea a mano.
        $separator = $request->input('separator') === ',' ? ',' : ';';
        $withHeaders = $request->boolean('headers', true);

        $rows = Accommodation::with(['city', 'state', 'type'])
            ->visibleTo($request->user())
            ->when(
                $validated['search'] ?? null,
                fn ($q, $search) => $q->where('name', 'like', "%{$search}%"),
            )
            ->when(
                $request->has('enabled'),
                fn ($q) => $q->where('enabled', $request->boolean('enabled')),
            )
            ->orderBy('name')
            ->get();

        $filename = 'catalogo-asociados-'.now()->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows, $available, $selected, $separator, $withHeaders) {
            $out = fopen('php://output', 'w');

            // BOM UTF-8: sin él Excel-ES rompe los acentos al abrir el .csv.
            fwrite($out, "\xEF\xBB\xBF");

            if ($withHeaders) {
                $headers = array_map(fn ($key) => $available[$key][0], $selected);
                fwrite($out, $this->line($headers, $separator));
            }

            foreach ($rows as $accommodation) {
                $cells = array_map(
                    fn ($key) => (string) ($available[$key][1]($accommodation) ?? ''),
                    $selected,
                );
                fwrite($out, $this->line($cells, $separator));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Cuántas filas lleva el archivo, para que el portal distinga un
            // export vacío de uno con datos sin tener que parsear el CSV.
            'X-Export-Rows' => (string) $rows->count(),
        ]);
    }

    /** Arma una línea CSV con escapado RFC 4180 y terminador CRLF. */
    private function line(array $cells, string $separator): string
    {
        $escaped = array_map(function (string $value) use ($separator) {
            if (
                str_contains($value, $separator)
                || str_contains($value, '"')
                || str_contains($value, "\n")
                || str_contains($value, "\r")
            ) {
                return '"'.str_replace('"', '""', $value).'"';
            }

            return $value;
        }, $cells);

        return implode($separator, $escaped)."\r\n";
    }
}
