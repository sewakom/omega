<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Restaurant extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'restaurant';

    protected $fillable = [
        'name', 'slug', 'logo', 'address', 'phone', 'email',
        'vat_number', 'currency', 'timezone', 'settings', 'active'
    ];

    protected $casts = [
        'settings' => 'json',
        'active'   => 'boolean'
    ];

    public function users() { return $this->hasMany(User::class); }
    public function floors() { return $this->hasMany(Floor::class); }
}
