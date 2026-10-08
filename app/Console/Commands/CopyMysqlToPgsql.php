<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copia los datos de la base MySQL (`mysql_source`) a la base Postgres
 * (conexión por defecto), que tiene que estar recién migrada con
 * `database/migrations` (`2026_10_08_*`).
 *
 * Copia tabla por tabla, en orden de FKs, sólo las columnas que existen en los
 * dos lados, y normaliza lo que Postgres rechaza:
 * - fechas cero o con día/mes 0 (`0000-00-00`, `2019-05-00`) → NULL;
 * - tinyint 0/1 → boolean donde la columna destino es boolean;
 * - blobs → literal hex de `bytea`;
 * - bytes NUL dentro de textos (Postgres no los admite en `text`).
 * Al final mueve las secuencias de los `id` al máximo copiado y compara conteos.
 *
 * No toca el origen. El destino lo vacía (TRUNCATE) sólo con `--truncate`.
 */
class CopyMysqlToPgsql extends Command
{
    protected $signature = 'db:copy-from-mysql
        {--truncate : Vacía las tablas destino antes de copiar}
        {--chunk=1000 : Filas por INSERT}';

    protected $description = 'Copia los datos de MySQL (mysql_source) a la base Postgres por defecto';

    /** Tablas que no se copian: las maneja Laravel o dejaron de existir en el esquema nuevo. */
    private const SKIP = ['migrations'];

    public function handle(): int
    {
        $target = DB::connection();
        $source = DB::connection('mysql_source');

        if ($target->getDriverName() !== 'pgsql') {
            $this->error('La conexión por defecto tiene que ser pgsql (DB_CONNECTION=pgsql).');

            return self::FAILURE;
        }

        $sourceTables = collect($source->select(
            'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = ?',
            ['BASE TABLE']
        ))->pluck('name')->all();

        $tables = array_values(array_filter(
            $this->tablesInFkOrder($target),
            fn (string $t) => ! in_array($t, self::SKIP, true) && in_array($t, $sourceTables, true)
        ));

        $notCopied = array_diff($sourceTables, $tables, self::SKIP);
        if ($notCopied !== []) {
            $this->warn('Tablas de MySQL sin equivalente en Postgres (no se copian): '.implode(', ', $notCopied));
        }

        if (! $this->option('truncate') && $this->anyHasRows($target, $tables)) {
            $this->error('El destino ya tiene datos. Corré con --truncate para vaciarlo antes de copiar.');

            return self::FAILURE;
        }

        if ($this->option('truncate')) {
            $target->statement('TRUNCATE '.implode(', ', array_map(fn ($t) => '"'.$t.'"', $tables)).' RESTART IDENTITY CASCADE');
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $report = [];

        $target->transaction(function () use ($source, $target, $tables, $chunk, &$report) {
            foreach ($tables as $table) {
                $columns = $this->copyableColumns($source, $target, $table);
                $copied = $this->copyTable($source, $target, $table, $columns, $chunk);
                $this->resetSequence($target, $table);

                $expected = $source->table($table)->count();
                $report[] = [$table, $expected, $copied, $expected === $copied ? 'ok' : 'DIFERENCIA'];
            }
        });

        $this->table(['Tabla', 'MySQL', 'Postgres', ''], $report);

        $mismatches = array_filter($report, fn (array $row) => $row[3] !== 'ok');
        if ($mismatches !== []) {
            $this->error(count($mismatches).' tabla(s) con conteos distintos.');

            return self::FAILURE;
        }

        $this->info('Copia completa: '.count($report).' tablas, '.array_sum(array_column($report, 2)).' filas.');

        return self::SUCCESS;
    }

    /**
     * Tablas del destino ordenadas para que cada una se cargue después de las
     * que referencia (orden topológico sobre las FKs).
     *
     * @return list<string>
     */
    private function tablesInFkOrder(Connection $target): array
    {
        $tables = collect($target->select(
            'SELECT tablename AS name FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename'
        ))->pluck('name')->all();

        $deps = array_fill_keys($tables, []);
        foreach ($target->select(
            "SELECT tc.table_name AS child, ccu.table_name AS parent
               FROM information_schema.table_constraints tc
               JOIN information_schema.constraint_column_usage ccu
                 ON ccu.constraint_name = tc.constraint_name AND ccu.constraint_schema = tc.constraint_schema
              WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = current_schema()"
        ) as $fk) {
            if ($fk->child !== $fk->parent) {
                $deps[$fk->child][] = $fk->parent;
            }
        }

        $ordered = [];
        $visiting = [];
        $visit = function (string $table) use (&$visit, &$ordered, &$visiting, $deps) {
            if (in_array($table, $ordered, true)) {
                return;
            }
            if (isset($visiting[$table])) {
                throw new RuntimeException("Ciclo de FKs en {$table}");
            }
            $visiting[$table] = true;
            foreach ($deps[$table] ?? [] as $parent) {
                $visit($parent);
            }
            $ordered[] = $table;
        };

        foreach ($tables as $table) {
            $visit($table);
        }

        return $ordered;
    }

    /**
     * Columnas presentes en origen y destino, con el tipo de destino. Se excluyen
     * las generadas (`invitations.pending_*`): Postgres las calcula solo.
     *
     * @return array<string, string> columna => data_type de Postgres
     */
    private function copyableColumns(Connection $source, Connection $target, string $table): array
    {
        $sourceColumns = collect($source->select(
            'SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        ))->pluck('name')->all();

        $columns = [];
        foreach ($target->select(
            "SELECT column_name AS name, data_type AS type FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = ? AND is_generated = 'NEVER'
              ORDER BY ordinal_position",
            [$table]
        ) as $column) {
            if (in_array($column->name, $sourceColumns, true)) {
                $columns[$column->name] = $column->type;
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, string>  $columns
     */
    private function copyTable(Connection $source, Connection $target, string $table, array $columns, int $chunk): int
    {
        $order = in_array('id', array_keys($columns), true) ? 'id' : array_key_first($columns);
        $copied = 0;
        $offset = 0;

        do {
            $rows = $source->table($table)
                ->select(array_keys($columns))
                ->orderBy($order)
                ->offset($offset)
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $batch = $rows->map(function (object $row) use ($columns) {
                $values = [];
                foreach ($columns as $name => $type) {
                    $values[$name] = $this->convert($row->{$name}, $type);
                }

                return $values;
            })->all();

            $target->table($table)->insert($batch);

            $copied += count($batch);
            $offset += $chunk;
        } while ($rows->count() === $chunk);

        return $copied;
    }

    private function convert(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (true) {
            $type === 'boolean' => (bool) $value,
            $type === 'date', str_starts_with($type, 'timestamp') => $this->validDateOrNull((string) $value),
            $type === 'bytea' => '\\x'.bin2hex((string) $value),
            is_string($value) => str_replace("\0", '', $value),
            default => $value,
        };
    }

    private function validDateOrNull(string $value): ?string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    private function resetSequence(Connection $target, string $table): void
    {
        $sequence = $target->selectOne('SELECT pg_get_serial_sequence(?, ?) AS seq', [$table, 'id'])?->seq;

        if ($sequence === null) {
            return; // PK string (reservations, bookings, inquiries) o sin `id`.
        }

        $target->statement(
            "SELECT setval(?, COALESCE((SELECT MAX(id) FROM \"{$table}\"), 1), (SELECT MAX(id) FROM \"{$table}\") IS NOT NULL)",
            [$sequence]
        );
    }

    /**
     * @param  list<string>  $tables
     */
    private function anyHasRows(Connection $target, array $tables): bool
    {
        foreach ($tables as $table) {
            if ($target->table($table)->exists()) {
                return true;
            }
        }

        return false;
    }
}
