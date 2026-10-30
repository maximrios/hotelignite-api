<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\Contracts\UserInvitationInterface;
use Illuminate\Console\Command;

/**
 * Recordatorio a los users que no activaron su acceso a los N días del email de
 * bienvenida (`api.user_invitation_reminder_days`). Uno solo por invitación.
 * Agendado en `routes/console.php`.
 */
class RemindUserInvitations extends Command
{
    protected $signature = 'users:remind-invitations';

    protected $description = 'Manda el recordatorio a las invitaciones de acceso sin activar';

    public function handle(UserInvitationInterface $invitations): int
    {
        $sent = $invitations->sendReminders();

        $this->info("Recordatorios enviados: {$sent}");

        return self::SUCCESS;
    }
}
