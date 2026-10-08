<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El dump legacy trae columnas de fecha con la convención de CakePHP
     * (`created` / `modified`), muchas con default `'0000-00-00 00:00:00'` y/o
     * con filas que guardan esa fecha cero. MySQL 8 corre con NO_ZERO_DATE +
     * STRICT_TRANS_TABLES, así que **cualquier** ALTER sobre esas tablas falla
     * con "Invalid default value" / "Incorrect datetime value" aunque no toque
     * esas columnas: al reconstruir la tabla, MySQL revalida la definición y los
     * datos completos.
     *
     * Se normalizan a `NULL DEFAULT NULL` y los valores cero pasan a NULL, que
     * es lo que esos registros significan en realidad ("sin fecha"). Sólo se
     * procesan las tablas que las migraciones siguientes modifican; el resto del
     * legacy (cash*, reservations_*) queda intacto hasta que haga falta tocarlo.
     */
    private const LEGACY_TABLES = [
        'rooms',
        'guests',
        'reservations',
        'services',
        'channels',
        'plans',
        'accounts',
        'users',
    ];

    private const ZERO_DATETIME = '0000-00-00 00:00:00';

    private const ZERO_DATE = '0000-00-00';

    public function up(): void
    {
        $originalSqlMode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;

        // Sin NO_ZERO_DATE/NO_ZERO_IN_DATE el ALTER puede reescribir la columna
        // conservando las filas con fecha cero, que después se pasan a NULL.
        DB::statement("SET SESSION sql_mode = REPLACE(REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', '')");

        try {
            foreach (self::LEGACY_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                foreach ($this->dateColumns($table) as $column) {
                    $zero = $column->DATA_TYPE === 'date' ? self::ZERO_DATE : self::ZERO_DATETIME;

                    $hasZeroDefault = str_starts_with((string) $column->COLUMN_DEFAULT, self::ZERO_DATE);
                    $hasZeroValues = DB::table($table)->where($column->COLUMN_NAME, $zero)->exists();

                    if (! $hasZeroDefault && ! $hasZeroValues) {
                        continue;
                    }

                    $type = strtoupper($column->COLUMN_TYPE);
                    DB::statement("ALTER TABLE `{$table}` MODIFY `{$column->COLUMN_NAME}` {$type} NULL DEFAULT NULL");
                    DB::table($table)->where($column->COLUMN_NAME, $zero)->update([$column->COLUMN_NAME => null]);
                }
            }
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$originalSqlMode]);
        }
    }

    /**
     * Columnas de fecha de una tabla, con su tipo y default declarados.
     */
    private function dateColumns(string $table): array
    {
        return DB::select(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, COLUMN_DEFAULT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND DATA_TYPE IN (?, ?, ?)',
            [$table, 'date', 'datetime', 'timestamp']
        );
    }

    public function down(): void
    {
        // No se revierte: restaurar defaults/valores de fecha cero volvería a
        // romper cualquier ALTER futuro bajo el sql_mode por defecto de MySQL 8.
    }
};
