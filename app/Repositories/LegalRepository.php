<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Aceptación de términos, privacidad y autorización de uso de datos agregados
 * (`config/legal.php`). Las tres son obligatorias para usar el PMS (decisión
 * del 2026-10-28; pendiente de revisión legal). Se registra la versión, fecha,
 * IP y navegador; una versión nueva vuelve a pedirse.
 */
class LegalRepository
{
    public function status(User $user): array
    {
        $documents = config('legal.documents');
        $accepted = LegalAcceptance::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->get()
            ->groupBy('document');

        $items = collect($documents)->map(function (array $doc, string $key) use ($accepted) {
            $current = $accepted->get($key)?->firstWhere('version', $doc['version']);

            return [
                'document' => $key,
                'title' => $doc['title'],
                'version' => $doc['version'],
                'path' => $doc['path'],
                'accepted_at' => $current?->accepted_at?->toIso8601String(),
            ];
        })->values();

        return [
            'documents' => $items,
            'all_accepted' => $items->every(fn (array $d) => $d['accepted_at'] !== null),
        ];
    }

    /** Acepta las versiones vigentes de los documentos pedidos (todos los pendientes). */
    public function accept(User $user, array $documents, ?string $ip, ?string $userAgent): array
    {
        $config = config('legal.documents');
        $pending = collect($this->status($user)['documents'])->whereNull('accepted_at')->pluck('document');
        $missing = $pending->diff($documents);

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['documents' => 'Para continuar tenés que aceptar todos los documentos.']);
        }

        foreach ($pending as $document) {
            LegalAcceptance::create([
                'user_id' => $user->id,
                'account_id' => $user->account_id,
                'document' => $document,
                'version' => $config[$document]['version'],
                'accepted_at' => now(),
                'ip' => $ip,
                'user_agent' => $userAgent !== null ? substr($userAgent, 0, 255) : null,
            ]);
        }

        return $this->status($user);
    }
}
