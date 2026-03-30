<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAuthState extends Model
{
    public $timestamps = false;

    protected $table = 'admin_auth_state';

    protected $guarded = [];

    protected $primaryKey = 'id';

    public $incrementing = false;
}
