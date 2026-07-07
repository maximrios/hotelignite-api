<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Client externo (agencia, gobierno, empresa) con acceso de solo lectura a
 * los accommodations relacionados vía el pivote `accommodation_client`.
 */
class Client extends Model
{
    use SoftDeletes;

    public const TYPE_AGENCY = 'agency';
    public const TYPE_GOVERNMENT = 'government';
    public const TYPE_COMPANY = 'company';

    protected $fillable = [
        'name',
        'type',
        'email',
        'phone',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function accommodations()
    {
        return $this->belongsToMany(Accommodation::class, 'accommodation_client');
    }
}
