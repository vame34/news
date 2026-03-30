<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'admin_audit_logs';

    protected $guarded = [];
}
