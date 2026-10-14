<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanItem extends Model
{
    protected $table = 'library.loan_item';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['due_at' => 'datetime', 'returned_at' => 'datetime'];
    public function loan() { return $this->belongsTo(LibraryLoan::class, 'loan_id'); }
}
