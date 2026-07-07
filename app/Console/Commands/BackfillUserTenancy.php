<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Asigna tenencia a los users existentes tras agregar user_type/account_id.
 *
 * Sin esto, los users previos quedan como `account` sin account_id y no verían
 * ningún dato. Por defecto los promueve a `platform` (nadie queda bloqueado al
 * lanzar); con --account=ID los asigna como users de esa cuenta.
 */
class BackfillUserTenancy extends Command
{
    protected $signature = 'users:backfill-tenancy
        {--account= : Asigna todos los users a esta cuenta como user_type=account}
        {--only-null : Solo afecta users sin account_id ni client_id}';

    protected $description = 'Backfill de tenencia (user_type/account_id) para users existentes';

    public function handle(): int
    {
        $accountId = $this->option('account');

        $query = User::query();
        if ($this->option('only-null')) {
            $query->whereNull('account_id')->whereNull('client_id');
        }

        if ($accountId !== null) {
            if (! Account::whereKey($accountId)->exists()) {
                $this->error("La cuenta {$accountId} no existe.");

                return self::FAILURE;
            }

            $affected = $query->update([
                'user_type' => User::TYPE_ACCOUNT,
                'account_id' => $accountId,
                'client_id' => null,
            ]);

            $this->info("{$affected} user(s) asignados a la cuenta {$accountId} como account.");

            return self::SUCCESS;
        }

        $affected = $query->update([
            'user_type' => User::TYPE_PLATFORM,
            'account_id' => null,
            'client_id' => null,
        ]);

        $this->info("{$affected} user(s) marcados como platform (super-admin).");
        $this->warn('Revisá que esto sea lo que querés: los users platform bypassan la tenencia.');

        return self::SUCCESS;
    }
}
