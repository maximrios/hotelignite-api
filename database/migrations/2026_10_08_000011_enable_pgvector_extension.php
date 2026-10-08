<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extensión `vector` (pgvector): tipo `vector(n)` e índices HNSW / IVFFlat para
 * búsqueda semántica con embeddings. Se habilita ahora para que la base esté
 * lista; las columnas se crean cuando se elija el modelo de embeddings, porque
 * `n` (la dimensión) depende de él.
 *
 * No viene en ninguna imagen oficial: la compila `docker/postgres/Dockerfile`.
 * Con otra imagen esta migración falla con "extension vector is not available".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
