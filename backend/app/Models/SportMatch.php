<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportMatch extends Model
{
    public $timestamps = false;

    protected $table = 'matches';

    protected $guarded = [];
}
