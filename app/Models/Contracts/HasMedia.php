<?php

namespace App\Models\Contracts;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Modelo con multimedia polimórfica (`media`). Hoy, `Event`. */
interface HasMedia
{
    /** @return MorphMany<Media, Model> */
    public function media(): MorphMany;
}
