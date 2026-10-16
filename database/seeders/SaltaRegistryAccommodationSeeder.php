<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Carga el registro provincial de alojamientos de Salta (Excel del 2026-10-08,
 * convertido a CSV en `data/`). No va en `DatabaseSeeder`: se corre a mano.
 *
 *   DRY_RUN=1 php artisan db:seed --class=SaltaRegistryAccommodationSeeder
 *   php artisan db:seed --class=SaltaRegistryAccommodationSeeder
 *
 * Reglas:
 * - Lo nuevo entra como **borrador**: `account_id = null` y `enabled = false`.
 *   `enabled` es lo que filtran las búsquedas públicas (`client/v1`, MCP); el
 *   borrador no se publica hasta que alguien lo reclame y lo habilite.
 * - Lo que ya existe (`match` en el archivo de decisiones, por slug) **sólo se
 *   completa**: se escriben los campos vacíos (`null`, `''`, `'0'`, `0`) y nunca
 *   se pisa un dato cargado. Las diferencias quedan en `accommodation_imports.conflicts`.
 * - Una fila sin decisión que se parece a un alojamiento previo (mismo email o
 *   mismo nombre en la misma ciudad) **no se crea**: se reporta para revisar y
 *   se resuelve agregándola a las decisiones.
 * - Idempotente: cada fila queda en `accommodation_imports` con `(source, source_key)`
 *   único, y re-correr saltea las ya importadas.
 * - Los parajes van al municipio y el nombre del paraje a `address2`.
 */
class SaltaRegistryAccommodationSeeder extends Seeder
{
    private const SOURCE = 'salta-registro-2026-10-08';

    private const DATA_FILE = __DIR__.'/data/salta-registro-2026-10-08.csv';

    private const DECISIONS_FILE = __DIR__.'/data/salta-registro-2026-10-08.decisiones.csv';

    private const TIMEZONE = 'America/Argentina/Salta';

    /** Localidades del registro que en `cities` tienen otro nombre. */
    private const CITY_ALIASES = [
        'salta' => 'Salta Capital',
        'salvador mazza' => 'Profesor Salvador Mazza',
    ];

    /** Municipios del registro que todavía no están en `cities`. */
    private const NEW_CITIES = [
        'Angastaco',
        'Animaná',
        'Campo Quijano',
        'Los Toldos',
        'Molinos',
        'Rosario de la Frontera',
        'San Carlos',
        'San José de Metán',
        'San Ramón de la Nueva Orán',
        'Seclantas',
        'Tolar Grande',
    ];

    /** Paraje del registro => [municipio, nombre del paraje para `address2`]. */
    private const PARAJES = [
        'castellanos' => ['San Lorenzo', 'Castellanos'],
        'el barrial' => ['San Carlos', 'El Barrial'],
        'la calderilla' => ['La Caldera', 'La Calderilla'],
        'la paya' => ['Cachi', 'La Paya'],
        'la silleta' => ['Rosario de Lerma', 'La Silleta'],
        'lesser' => ['San Lorenzo', 'Lesser'],
        'los banos' => ['Rosario de la Frontera', 'Los Baños'],
        'los los' => ['Chicoana', 'Los Los'],
        'san luis' => ['Salta Capital', 'San Luis'],
        'talapampa' => ['La Viña', 'Talapampa'],
        'tolombon' => ['Cafayate', 'Tolombón'],
    ];

    /** Tipos que tienen que existir; si falta alguno el catálogo no es el esperado. */
    private const EXISTING_TYPES = [
        'Hotel 1 estrella',
        'Hotel 2 estrellas',
        'Hotel 3 estrellas',
        'Hotel 4 estrellas',
        'Hotel 5 estrellas',
        'Hotel Boutique',
        'Apart hotel',
        'Cabañas',
        'Alquiler temporario',
    ];

    /** Clases del registro que no tenían tipo propio. */
    private const NEW_TYPES = ['Hostería', 'Residencial A', 'Residencial B'];

    /** Campos que se completan en un alojamiento existente si están vacíos. */
    private const ENRICHABLE = [
        'type_id',
        'stars',
        'address',
        'address2',
        'email',
        'phone',
        'phone_reservations',
        'web',
        'city_id',
        'state_id',
        'country_id',
        'language_id',
        'currency_id',
        'timezone',
    ];

    /** Siempre en minúscula, salvo al principio del nombre. */
    private const LOWERCASE_WORDS = ['de', 'del', 'y', 'en', 'al'];

    /** En minúscula sólo después de una preposición: "Hotel de la Linda", pero "Hostal El Aljibe". */
    private const ARTICLES = ['la', 'las', 'los', 'el'];

    /** Palabras que no distinguen un nombre de otro al buscar duplicados. */
    private const GENERIC_WORDS = [
        'hotel', 'hostal', 'hosteria', 'hostel', 'cabana', 'cabanas', 'apart', 'residencial',
        'boutique', 'suites', 'departamento', 'departamentos', 'de', 'del', 'la', 'las', 'los', 'el', 'y',
    ];

    /** @var array<string, int> */
    private array $counts = [
        'creados' => 0,
        'completados' => 0,
        'ya importados' => 0,
        'descartados' => 0,
        'para revisar' => 0,
    ];

    /** @var list<array{int, string, string}> */
    private array $review = [];

    /** @var array<string, int> */
    private array $conflictFields = [];

    public function run(): void
    {
        $dryRun = filter_var(env('DRY_RUN', false), FILTER_VALIDATE_BOOL);

        DB::beginTransaction();

        try {
            $this->import();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $this->report($dryRun);
    }

    private function import(): void
    {
        // `fila` es el número de fila en el Excel (encabezado = 1): la clave estable
        // de cada registro de esta fuente, y la que usa el archivo de decisiones.
        $rows = [];
        foreach ($this->readCsv(self::DATA_FILE) as $i => $row) {
            $rows[] = ['fila' => $i + 2] + $row;
        }
        $decisions = [];
        foreach ($this->readCsv(self::DECISIONS_FILE) as $decision) {
            $decisions[(int) $decision['fila']] = $decision;
        }

        $stateId = $this->saltaStateId();
        $cities = $this->ensureCities($stateId);
        $types = $this->ensureTypes();

        $imported = DB::table('accommodation_imports')
            ->where('source', self::SOURCE)
            ->pluck('source_key')
            ->flip();

        [$byEmail, $byName] = $this->indexPreviousAccommodations();

        foreach ($rows as $row) {
            $fila = $row['fila'];
            $sourceKey = "fila-{$fila}";
            $decision = $decisions[$fila] ?? null;

            if ($imported->has($sourceKey)) {
                $this->counts['ya importados']++;

                continue;
            }

            if ($decision !== null && $decision['accion'] === 'skip') {
                $this->counts['descartados']++;

                continue;
            }

            $attributes = $this->attributesFor($row, $cities, $stateId, $types);

            if ($decision !== null && $decision['accion'] === 'match') {
                $existing = Accommodation::where('slug', $decision['slug'])->orderBy('id')->first();

                if ($existing !== null) {
                    $this->enrich($existing, $attributes, $row, $sourceKey);

                    continue;
                }
                // El slug no existe en este entorno: se trata como fila sin decisión.
                $decision = null;
            }

            if ($decision === null) {
                $previous = $this->lookalike($attributes, $byEmail, $byName);

                if ($previous !== null) {
                    $this->counts['para revisar']++;
                    $this->review[] = [$fila, $attributes['name'], $previous];

                    continue;
                }
            }

            $accommodation = Accommodation::create($attributes);
            $this->recordImport($accommodation->id, $sourceKey, 'created', $row);
            $this->counts['creados']++;
        }
    }

    /**
     * Completa sólo lo vacío. Lo que difiere se registra, no se toca.
     *
     * @param  array<string, string|int|bool|null>  $attributes
     * @param  array<string, string|int>  $row
     */
    private function enrich(Accommodation $accommodation, array $attributes, array $row, string $sourceKey): void
    {
        $changes = [];
        $conflicts = [];

        foreach (self::ENRICHABLE as $field) {
            $incoming = $attributes[$field] ?? null;
            if ($this->isEmpty($incoming)) {
                continue;
            }

            $current = $accommodation->getAttribute($field);
            if ($this->isEmpty($current)) {
                $changes[$field] = $incoming;
            } elseif ($this->comparable($field, $current) !== $this->comparable($field, $incoming)) {
                $conflicts[$field] = ['base' => $current, 'registro' => $incoming];
            }
        }

        if ($this->comparable('name', $accommodation->name) !== $this->comparable('name', $attributes['name'])) {
            $conflicts['name'] = ['base' => $accommodation->name, 'registro' => $attributes['name']];
        }

        if ($changes !== []) {
            $accommodation->forceFill($changes)->save();
        }

        foreach (array_keys($conflicts) as $field) {
            $this->conflictFields[$field] = ($this->conflictFields[$field] ?? 0) + 1;
        }

        $this->recordImport($accommodation->id, $sourceKey, 'enriched', $row, $changes, $conflicts);
        $this->counts['completados']++;
    }

    /**
     * @param  array<string, string|int>  $row
     * @param  array<string, int>  $cities
     * @param  array<string, int>  $types
     * @return array<string, string|int|bool|null>
     */
    private function attributesFor(array $row, array $cities, int $stateId, array $types): array
    {
        $locality = $this->key((string) $row['localidad']);
        $paraje = self::PARAJES[$locality] ?? null;
        $cityName = $paraje[0] ?? self::CITY_ALIASES[$locality] ?? (string) $row['localidad'];
        $cityId = $cities[$this->key($cityName)]
            ?? throw new RuntimeException("Fila {$row['fila']}: la localidad '{$row['localidad']}' no resuelve a ninguna ciudad.");

        $phone = Str::squish((string) $row['telefono']);
        $mobile = Str::squish((string) $row['celular']);

        return [
            'account_id' => null,
            'name' => $this->displayName((string) $row['nombre']),
            'type_id' => $types[$this->key($this->typeName($row))],
            'stars' => $this->stars($row),
            'address' => Str::squish((string) $row['direccion']) ?: null,
            'address2' => $paraje[1] ?? null,
            'email' => Str::lower(trim((string) $row['email'])) ?: null,
            'phone' => $phone ?: ($mobile ?: null),
            'phone_reservations' => $phone !== '' && $mobile !== '' ? $mobile : null,
            'web' => trim((string) $row['web']) ?: null,
            'city_id' => $cityId,
            'state_id' => $stateId,
            'country_id' => 'AR',
            'language_id' => 'es',
            'currency_id' => 'ARS',
            'timezone' => self::TIMEZONE,
            'enabled' => false,
        ];
    }

    /** @param  array<string, string|int>  $row */
    private function typeName(array $row): string
    {
        $class = (string) $row['clase'];
        $category = (string) $row['categoria'];
        $stars = $this->stars($row);

        return match ($class) {
            'Hotel' => match (true) {
                $category === 'Boutique' => 'Hotel Boutique',
                $stars === 1 => 'Hotel 1 estrella',
                $stars !== null => "Hotel {$stars} estrellas",
                default => throw new RuntimeException("Fila {$row['fila']}: categoría de hotel desconocida '{$category}'."),
            },
            'Apart Hotel' => 'Apart hotel',
            'Cabaña' => 'Cabañas',
            'Hostería' => 'Hostería',
            'Residencial' => in_array($category, ['A', 'B'], true)
                ? "Residencial {$category}"
                : throw new RuntimeException("Fila {$row['fila']}: categoría de residencial desconocida '{$category}'."),
            'Inmuebles' => 'Alquiler temporario',
            default => throw new RuntimeException("Fila {$row['fila']}: clase desconocida '{$class}'."),
        };
    }

    /** @param  array<string, string|int>  $row */
    private function stars(array $row): ?int
    {
        return preg_match('/^([1-5])\s*\*$/', trim((string) $row['categoria']), $m) ? (int) $m[1] : null;
    }

    /**
     * El registro viene en mayúsculas: "HOTEL DE LA LINDA" → "Hotel de la Linda".
     * Los nombres que ya traen minúsculas se respetan.
     */
    private function displayName(string $name): string
    {
        $name = Str::squish($name);
        if (mb_strtoupper($name) !== $name) {
            return $name;
        }

        $words = explode(' ', $name);
        foreach ($words as $i => $word) {
            $lower = mb_strtolower($word);
            $afterPreposition = $i > 0 && in_array($words[$i - 1], self::LOWERCASE_WORDS, true);
            if ($i > 0 && (in_array($lower, self::LOWERCASE_WORDS, true) || ($afterPreposition && in_array($lower, self::ARTICLES, true)))) {
                $words[$i] = $lower;
            } elseif (! preg_match('/^(?:I{1,3}|IV|VI{0,3}|IX|X)$/u', $word)) {
                $words[$i] = preg_replace_callback('/\p{L}+/u', fn (array $m) => mb_convert_case($m[0], MB_CASE_TITLE), $word);
            }
        }

        return implode(' ', $words);
    }

    private function saltaStateId(): int
    {
        $id = DB::table('states')
            ->join('countries', 'countries.id', '=', 'states.country_id')
            ->where('countries.iso_code', 'AR')
            ->where('states.name', 'Salta')
            ->value('states.id');

        return $id !== null ? (int) $id : throw new RuntimeException('No existe la provincia Salta (AR) en `states`.');
    }

    /** @return array<string, int> nombre normalizado => id */
    private function ensureCities(int $stateId): array
    {
        $cities = DB::table('cities')->where('state_id', $stateId)->pluck('id', 'name')
            ->mapWithKeys(fn (int $id, string $name) => [$this->key($name) => $id])
            ->all();

        foreach (self::NEW_CITIES as $name) {
            if (isset($cities[$this->key($name)])) {
                continue;
            }

            $slug = Str::slug($name);
            if (DB::table('cities')->where('slug', $slug)->exists()) {
                throw new RuntimeException("El slug de ciudad '{$slug}' ya está usado por otra provincia.");
            }

            $cities[$this->key($name)] = DB::table('cities')->insertGetId([
                'state_id' => $stateId,
                'name' => $name,
                'slug' => $slug,
                'created' => now(),
                'modified' => now(),
                'enabled' => 0,
            ]);
        }

        return $cities;
    }

    /** @return array<string, int> nombre normalizado => id */
    private function ensureTypes(): array
    {
        $types = DB::table('accommodation_types')->pluck('id', 'name')
            ->mapWithKeys(fn (int $id, string $name) => [$this->key($name) => $id])
            ->all();

        foreach (self::EXISTING_TYPES as $name) {
            if (! isset($types[$this->key($name)])) {
                throw new RuntimeException("Falta el tipo de alojamiento '{$name}'.");
            }
        }

        foreach (self::NEW_TYPES as $name) {
            $types[$this->key($name)] ??= DB::table('accommodation_types')->insertGetId(['name' => $name]);
        }

        return $types;
    }

    /**
     * Alojamientos que no vienen de esta importación, indexados por ciudad+email
     * y ciudad+nombre, para no duplicar uno que el archivo de decisiones no cubre.
     *
     * @return array{array<string, string>, array<string, string>}
     */
    private function indexPreviousAccommodations(): array
    {
        $byEmail = [];
        $byName = [];

        $previous = Accommodation::query()
            ->whereNotIn('id', DB::table('accommodation_imports')->where('source', self::SOURCE)->select('accommodation_id'))
            ->get(['id', 'name', 'email', 'city_id']);

        foreach ($previous as $accommodation) {
            $label = "#{$accommodation->id} {$accommodation->name}";
            $email = Str::lower(trim((string) $accommodation->email));
            if (! $this->isEmpty($email)) {
                $byEmail["{$accommodation->city_id}|{$email}"] = $label;
            }
            $name = $this->nameKey((string) $accommodation->name);
            if ($name !== '') {
                $byName["{$accommodation->city_id}|{$name}"] = $label;
            }
        }

        return [$byEmail, $byName];
    }

    /**
     * @param  array<string, string|int|bool|null>  $attributes
     * @param  array<string, string>  $byEmail
     * @param  array<string, string>  $byName
     */
    private function lookalike(array $attributes, array $byEmail, array $byName): ?string
    {
        $city = $attributes['city_id'];

        if ($attributes['email'] !== null && isset($byEmail["{$city}|{$attributes['email']}"])) {
            return $byEmail["{$city}|{$attributes['email']}"].' (email)';
        }

        $name = $this->nameKey((string) $attributes['name']);

        return $name !== '' && isset($byName["{$city}|{$name}"]) ? $byName["{$city}|{$name}"].' (nombre)' : null;
    }

    /**
     * @param  array<string, string|int>  $row
     * @param  array<string, string|int|bool|null>  $changes
     * @param  array<string, array{base: mixed, registro: mixed}>  $conflicts
     */
    private function recordImport(int $accommodationId, string $sourceKey, string $action, array $row, array $changes = [], array $conflicts = []): void
    {
        DB::table('accommodation_imports')->insert([
            'accommodation_id' => $accommodationId,
            'source' => self::SOURCE,
            'source_key' => $sourceKey,
            'action' => $action,
            'payload' => json_encode($row, JSON_UNESCAPED_UNICODE),
            'changes' => $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            'conflicts' => $conflicts === [] ? null : json_encode($conflicts, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r') ?: throw new RuntimeException("No se puede leer {$path}.");
        $header = fgetcsv($handle, escape: '');
        $rows = [];

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if ($values === [null]) {
                throw new RuntimeException("{$path} tiene una línea vacía: correría la numeración de filas.");
            }
            $rows[] = array_combine($header, array_map(fn (?string $v) => trim((string) $v), $values));
        }

        fclose($handle);

        return $rows;
    }

    private function report(bool $dryRun): void
    {
        $command = $this->command;
        if ($command === null) {
            return;
        }

        $command->info(($dryRun ? '[DRY RUN — no se escribió nada] ' : '').'Registro '.self::SOURCE);
        $command->table(['Resultado', 'Filas'], collect($this->counts)->map(fn (int $n, string $k) => [$k, $n])->values()->all());

        if ($this->conflictFields !== []) {
            arsort($this->conflictFields);
            $command->line('Diferencias base vs. registro en alojamientos existentes (se dejó la base):');
            $command->table(['Campo', 'Alojamientos'], collect($this->conflictFields)->map(fn (int $n, string $k) => [$k, $n])->values()->all());
        }

        if ($this->review !== []) {
            $command->warn('Filas no creadas por parecerse a un alojamiento previo — agregarlas a las decisiones (match/new):');
            $command->table(['Fila', 'Registro', 'Se parece a'], $this->review);
        }
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === '0' || $value === 0;
    }

    /**
     * Forma comparable de un valor, para no reportar como diferencia lo que es
     * sólo formato: teléfonos por sus últimos 7 dígitos (sin prefijo ni 15), webs
     * sin esquema, `www.` ni barra final.
     */
    private function comparable(string $field, mixed $value): string
    {
        $value = $this->key((string) $value);

        return match ($field) {
            'phone', 'phone_reservations' => substr(preg_replace('/\D/', '', $value), -7),
            'web' => rtrim(preg_replace('#^(https?://)?(www\.)?#', '', $value), '/'),
            default => $value,
        };
    }

    private function key(string $value): string
    {
        return Str::squish(Str::lower(Str::ascii($value)));
    }

    private function nameKey(string $name): string
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_diff($words, self::GENERIC_WORDS));
    }
}
