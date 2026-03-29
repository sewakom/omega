<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'category';

    protected $fillable = ['restaurant_id', 'parent_id', 'name', 'image', 'order', 'active'];

    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function parent()     { return $this->belongsTo(Category::class, 'parent_id'); }
    public function children()   { return $this->hasMany(Category::class, 'parent_id'); }
    public function products()   { return $this->hasMany(Product::class); }
}
