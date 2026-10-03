<?php

namespace App\Models;

class BillmanagerServiceDetail extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['details'];

    protected $casts = ['details' => 'encrypted:array'];
}
