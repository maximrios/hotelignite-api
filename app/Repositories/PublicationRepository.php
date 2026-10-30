<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Client;
use App\Models\User;
use App\Repositories\Contracts\PublicationInterface;
use App\Support\AssociateType;
use Illuminate\Support\Facades\DB;

/**
 * El gate de publicación: el "Habilitar" del client sobre su padrón. Es la
 * palanca del municipio o la cámara, no una aprobación del staff.
 *
 * Reglas para habilitar (quitar no tiene ninguna):
 *   - el asociado está en el padrón y el pivote está `active`;
 *   - tiene dueño — el borrador no publica (no hay a quién exigirle la ficha);
 *   - si el client es cámara o gobierno, aceptó el vínculo (`agreed_at`).
 */
class PublicationRepository implements PublicationInterface
{
    public const SKIP_NOT_IN_PADRON = 'not_in_padron';

    public const SKIP_REJECTED = 'rejected';

    public const SKIP_NO_OWNER = 'no_owner';

    public const SKIP_NOT_AGREED = 'not_agreed';

    public function setPublished(Client $client, User $by, string $type, array $ids, bool $published): array
    {
        ['pivot' => $pivot, 'key' => $key, 'entity' => $entity] = AssociateType::tables($type);
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $rows = DB::table("{$pivot} as p")
            ->join("{$entity} as e", 'e.id', '=', "p.{$key}")
            ->where('p.client_id', $client->id)
            ->whereIn("p.{$key}", $ids)
            ->whereNull('e.deleted_at')
            ->get(["p.{$key} as id", 'p.status', 'p.agreed_at', 'e.account_id'])
            ->keyBy('id');

        $updated = [];
        $skipped = [];

        foreach ($ids as $id) {
            $row = $rows->get($id);
            $reason = match (true) {
                $row === null => self::SKIP_NOT_IN_PADRON,
                ! $published => null,
                $row->status !== 'active' => self::SKIP_REJECTED,
                $row->account_id === null => self::SKIP_NO_OWNER,
                $client->publishRequiresAgreement() && $row->agreed_at === null => self::SKIP_NOT_AGREED,
                default => null,
            };

            if ($reason !== null) {
                $skipped[] = ['entity_id' => $id, 'reason' => $reason];

                continue;
            }

            $updated[] = $id;
        }

        if ($updated !== []) {
            DB::table($pivot)
                ->where('client_id', $client->id)
                ->whereIn($key, $updated)
                ->update($published
                    ? ['verified_at' => DB::raw('COALESCE(verified_at, NOW())'), 'verified_by_user_id' => $by->id, 'updated_at' => now()]
                    : ['verified_at' => null, 'verified_by_user_id' => null, 'updated_at' => now()]);
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }
}
