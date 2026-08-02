<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterSkuRow extends Model
{
    protected $fillable = [
        'marketplace', 'sku_id', 'barcode', 'short_title', 'long_title',
        'unit_cost', 'source_file', 'imported_at', 'imported_by',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:4',
        'imported_at' => 'datetime',
    ];
}
