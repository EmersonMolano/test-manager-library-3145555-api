<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class LibraryUser extends Authenticatable
{
    protected $table = 'library.user';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $hidden = ['password_hash'];
    protected $guarded = [];
}
