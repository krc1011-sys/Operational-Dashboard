<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualOverride extends Model
{
    protected $fillable = ['marketplace', 'po_id', 'sku_id', 'qty', 'updated_by'];
}
