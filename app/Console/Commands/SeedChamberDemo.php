<?php

namespace App\Console\Commands;

use App\Models\Accommodation;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\Invitation;
use App\Models\MembershipFee;
use App\Models\User;
use App\Repositories\Contracts\DuesInterface;
use App\Support\AssociateType;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cámara de demostración para presentar el portal de clients: un padrón con
 * alojamientos en todos los estados, socios publicados en la web, categorías
 * de cuota, cuatro meses de cuotas con pagos, deudores y morosos, y consultas
 * de viajeros. Se arma con alojamientos que ya existen (no crea fichas).
 *
 * No manda ningún mail: las invitaciones se insertan como pendientes y nunca
 * salen. No corre en producción: le asociaría hoteles reales a un client
 * ficticio. `--purge` lo borra todo.
 */
class SeedChamberDemo extends Command
{
    protected $signature = 'demo:camara
        {--city=Salta Capital : Ciudad de donde salen los alojamientos}
        {--password= : Contraseña del user demo (por defecto se genera)}
        {--purge : Borra la cámara demo y todo lo suyo}';

    protected $description = 'Crea (o recrea) una cámara de demostración con padrón, cuotas y consultas';

    private const CLIENT_NAME = 'Cámara Hotelera Demo';

    private const USER_EMAIL = 'demo.camara@hotelignite.com';

    /** Últimos meses con cuota emitida, el último es el actual. */
    private const MONTHS = 4;

    public function handle(DuesInterface $dues): int
    {
        if (app()->environment('production')) {
            $this->error('demo:camara no corre en producción: asociaría hoteles reales a un client ficticio.');

            return self::FAILURE;
        }

        $this->purge();
        if ($this->option('purge')) {
            $this->info('Cámara demo borrada.');

            return self::SUCCESS;
        }

        $city = (string) $this->option('city');
        $owned = $this->pick($city, owned: true, count: 13);
        $drafts = $this->pick($city, owned: false, count: 9);
        if ($owned->count() < 13 || $drafts->count() < 9) {
            $this->error("No hay suficientes alojamientos en {$city} (hacen falta 13 con dueño y 9 borradores).");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(12, symbols: false));
        $today = CarbonImmutable::today();

        [$client, $user] = DB::transaction(function () use ($owned, $drafts, $password, $today) {
            $client = Client::create([
                'name' => self::CLIENT_NAME,
                'type' => Client::TYPE_CHAMBER,
                'email' => 'camara.demo@example.com',
            ]);
            $user = User::create([
                'name' => 'Laura Gómez',
                'email' => self::USER_EMAIL,
                'password' => Hash::make($password),
                'user_type' => User::TYPE_CLIENT,
                'client_id' => $client->id,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->buildPadron($client, $user, $owned, $drafts, $today);

            return [$client, $user];
        });

        $members = $owned->take(10)->values();
        $this->buildDues($dues, $client, $user, $members, $today);
        $this->buildInquiries($client, $members->take(3), $today);

        $this->info('Cámara demo lista.');
        $this->table(['', ''], [
            ['Portal', rtrim((string) config('api.clients_portal_url'), '/').'/login'],
            ['Email', self::USER_EMAIL],
            ['Contraseña', $password],
            ['Padrón', '22: 10 socios (8 en la web), 2 sin vínculo, 1 rechazó, 4 invitados, 5 borradores'],
            ['Cuotas', self::MONTHS.' meses emitidos: 6 al día, 2 deben el mes, 2 morosos'],
        ]);

        return self::SUCCESS;
    }

    /** @return Collection<int, Accommodation> */
    private function pick(string $city, bool $owned, int $count): Collection
    {
        return Accommodation::query()
            ->whereHas('city', fn ($q) => $q->where('name', $city))
            ->when($owned, fn ($q) => $q->whereNotNull('account_id'), fn ($q) => $q->whereNull('account_id'))
            ->orderBy('id')
            ->limit($count)
            ->get();
    }

    /**
     * 13 con dueño: 10 socios (8 publicados), 2 sin vínculo y 1 que rechazó.
     * 9 borradores: 4 con invitación pendiente y 5 sin invitar.
     */
    private function buildPadron(Client $client, User $user, Collection $owned, Collection $drafts, CarbonImmutable $today): void
    {
        foreach ($owned->values() as $i => $accommodation) {
            $since = $today->subMonths(self::MONTHS + 1)->addDays($i * 3);
            $row = [
                'accommodation_id' => $accommodation->id,
                'client_id' => $client->id,
                'status' => $i === 12 ? 'rejected' : 'active',
                'agreed_at' => $i < 10 ? $since : null,
                'verified_at' => $i < 8 ? $since->addDays(2) : null,
                'verified_by_user_id' => $i < 8 ? $user->id : null,
                'created_at' => $since->subDays(10),
                'updated_at' => $since,
            ];
            DB::table('accommodation_client')->insert($row);
        }

        foreach ($drafts->values() as $i => $accommodation) {
            DB::table('accommodation_client')->insert([
                'accommodation_id' => $accommodation->id,
                'client_id' => $client->id,
                'status' => 'active',
                'created_at' => $today->subMonths(2),
                'updated_at' => $today->subMonths(2),
            ]);

            if ($i < 4) {
                Invitation::create([
                    'client_id' => $client->id,
                    'accommodation_id' => $accommodation->id,
                    'email' => "contacto+{$accommodation->id}@example.com",
                    'contact_name' => null,
                    'accommodation_name' => $accommodation->name,
                    'token_hash' => Invitation::hashToken(Str::random(64)),
                    'expires_at' => $today->addDays(20 - $i * 3),
                    'created_by_user_id' => $user->id,
                ]);
            }
        }
    }

    /**
     * Cuatro categorías, una por socio en rotación, y las cuotas de los últimos
     * meses emitidas con el mismo método que el botón "Emitir". Pagos: 6 al
     * día, 2 deben sólo el mes actual y 2 no pagan desde el primer mes
     * (quedan morosos).
     */
    private function buildDues(DuesInterface $dues, Client $client, User $user, Collection $members, CarbonImmutable $today): void
    {
        $fees = collect([
            ['Hotel 4 y 5 estrellas', 4_500_000],
            ['Hotel 1 a 3 estrellas', 3_200_000],
            ['Hostería y apart', 2_400_000],
            ['Hostel y cabañas', 1_600_000],
        ])->map(fn ($f) => MembershipFee::create([
            'client_id' => $client->id,
            'name' => $f[0],
            'amount' => $f[1],
            'due_day' => 10,
            'active' => true,
        ]));

        foreach ($members as $i => $accommodation) {
            $dues->assign($client, AssociateType::ACCOMMODATION, [$accommodation->id], $fees[$i % $fees->count()]->id);
        }

        $methods = ['transferencia', 'transferencia', 'debito', 'mercadopago', 'efectivo'];
        for ($m = self::MONTHS - 1; $m >= 0; $m--) {
            $month = $today->startOfMonth()->subMonths($m);
            $dues->emit($client, $user, $month->format('Y-m'));

            foreach ($members as $i => $accommodation) {
                $isCurrent = $m === 0;
                $paysThisMonth = match (true) {
                    $i < 6 => true,          // al día
                    $i < 8 => ! $isCurrent,  // deben el mes actual
                    default => $m === self::MONTHS - 1, // morosos: sólo pagaron el primero
                };
                $paidOn = $month->addDays(3 + ($i % 7));
                if (! $paysThisMonth || $paidOn->greaterThan($today)) {
                    continue;
                }

                $dues->record($client, $user, AssociateType::ACCOMMODATION, $accommodation->id, [
                    'kind' => 'payment',
                    'amount' => $fees[$i % $fees->count()]->amount,
                    'occurred_on' => $paidOn->format('Y-m-d'),
                    'method' => $methods[$i % count($methods)],
                    'reference' => 'Op. '.(48_200 + $m * 100 + $i),
                ]);
            }
        }
    }

    /** Consultas de viajeros sobre socios publicados, sin disparar los avisos por mail. */
    private function buildInquiries(Client $client, Collection $accommodations, CarbonImmutable $today): void
    {
        $guests = [
            ['Martina', 'Ruiz', 2, 1, 'Hola, ¿tienen habitación familiar para el fin de semana largo?'],
            ['Diego', 'Fernández', 2, 0, 'Quería saber si incluye desayuno y estacionamiento.'],
            ['Sofía', 'Alvarez', 4, 2, 'Somos dos familias, ¿tienen dos habitaciones comunicadas?'],
        ];

        Inquiry::withoutEvents(function () use ($client, $accommodations, $guests, $today) {
            foreach ($accommodations->values() as $i => $accommodation) {
                [$name, $lastname, $adults, $children, $message] = $guests[$i];
                Inquiry::forceCreate([
                    'name' => $name,
                    'lastname' => $lastname,
                    'email' => strtolower($name).'@example.com',
                    'phone' => '+54 387 '.(4_100_000 + $i * 1_731),
                    'adults' => $adults,
                    'childrens' => $children,
                    'checkin' => $today->addDays(14 + $i * 7),
                    'checkout' => $today->addDays(17 + $i * 7),
                    'message' => $message,
                    'status' => $i === 2 ? 'answered' : 'new',
                    'inquirable_type' => Accommodation::class,
                    'inquirable_id' => $accommodation->id,
                    'client_id' => $client->id,
                    'source' => 'web',
                ]);
            }
        });
    }

    /** Borra la cámara demo anterior, si existe. El libro no se borra por modelo (es append-only). */
    private function purge(): void
    {
        $client = Client::withTrashed()->where('name', self::CLIENT_NAME)->where('type', Client::TYPE_CHAMBER)->first();
        $user = User::withTrashed()->where('email', self::USER_EMAIL)->first();

        DB::transaction(function () use ($client, $user) {
            if ($client) {
                DB::table('member_ledger_entries')->where('client_id', $client->id)->delete();
                DB::table('member_fee_assignments')->where('client_id', $client->id)->delete();
                DB::table('membership_fees')->where('client_id', $client->id)->delete();
                DB::table('inquiries')->where('client_id', $client->id)->delete();
                DB::table('invitations')->where('client_id', $client->id)->delete();
                DB::table('accommodation_client')->where('client_id', $client->id)->delete();
            }
            if ($user) {
                $user->tokens()->delete();
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                $user->forceDelete();
            }
            $client?->users()->withTrashed()->forceDelete();
            $client?->forceDelete();
        });
    }
}
