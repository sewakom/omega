<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class CashSession extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'cash';

    protected $fillable = [
        'restaurant_id', 'user_id', 'opening_amount', 'closing_amount',
        'expected_amount', 'difference', 'closing_notes', 'opened_at', 'closed_at'
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function user()       { return $this->belongsTo(User::class); }
    public function payments()   { return $this->hasMany(Payment::class); }
}
