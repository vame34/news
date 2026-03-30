<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiGenerationLog extends Model
{
    public $timestamps = false;

    protected $table = 'ai_generation_logs';

    protected $guarded = [];
}
