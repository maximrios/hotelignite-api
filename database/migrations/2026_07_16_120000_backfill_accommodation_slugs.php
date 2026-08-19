<?php

use App\Models\Accommodation;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Rellena el slug de los accommodations que se crearon sin él (histórico:
     * el store nunca lo generaba). El hook `saving` del modelo se encarga de
     * generar un slug único al guardar cada registro.
     */
    public function up(): void
    {
        Accommodation::query()
            ->where(function ($query) {
                $query->whereNull('slug')->orWhere('slug', '');
            })
            ->orderBy('id')
            ->each(function (Accommodation $accommodation) {
                $accommodation->save();
            });
    }

    public function down(): void
    {
        // No se revierte: los slugs son identidad pública de cada alojamiento.
    }
};
