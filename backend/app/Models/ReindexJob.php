<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReindexJob extends Model
{
    public $timestamps = false;

    protected $table = 'reindex_jobs';

    protected $guarded = [];
}
