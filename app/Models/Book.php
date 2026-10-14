<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    protected $table = 'library.book';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['deleted_at' => 'datetime'];

    public function category() { return $this->belongsTo(Category::class, 'category_id'); }
}
