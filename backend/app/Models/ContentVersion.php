<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentVersion extends Model
{
    public $timestamps = false;

    protected $table = 'content_versions';

    protected $guarded = [];
}
