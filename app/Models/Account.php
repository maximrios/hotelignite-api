<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'plan_id',
        'account_type_id',
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'active',
        'test',
        'expiration_date',
        'comments',
        'agreement',
        'token',
    ];

    protected $casts = [
        'active'          => 'boolean',
        'test'            => 'boolean',
        'agreement'       => 'boolean',
        'expiration_date' => 'date',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function accountType()
    {
        return $this->belongsTo(AccountType::class);
    }

    public function accommodations()
    {
        return $this->hasMany(Accommodation::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
