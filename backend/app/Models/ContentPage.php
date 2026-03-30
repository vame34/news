<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentPage extends Model
{
    public $timestamps = false;

    protected $table = 'content_pages';

    protected $guarded = [];
}
