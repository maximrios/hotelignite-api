<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccommodation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{
    use BelongsToAccommodation, HasUuids;

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    /**
     * Canal por el que entró la consulta. `null` = origen sin atribuir
     * (histórico previo al campo, o formulario que no lo resolvió).
     */
    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }
}
