<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Qué categoría de cuota paga cada socio. Una por (client, asociado). */
class MemberFeeAssignment extends Model
{
    protected $fillable = ['client_id', 'member_type', 'member_id', 'membership_fee_id'];

    public function fee()
    {
        return $this->belongsTo(MembershipFee::class, 'membership_fee_id');
    }
}
