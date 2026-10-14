<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LibraryLoan extends Model
{
    protected $table = 'library.loan';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['loaned_at' => 'datetime'];
    public function items() { return $this->hasMany(LoanItem::class, 'loan_id'); }
    public function member() { return $this->belongsTo(Member::class, 'member_id'); }
}
