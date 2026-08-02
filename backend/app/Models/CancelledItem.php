<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CancelledItem extends Model
{
    protected $fillable = [
        'marketplace', 'po_id', 'sku_id', 'title', 'cancelled_qty',
        'quantity_confirmed', 'future_cancel_date', 'source_file', 'imported_at', 'imported_by',
    ];

    protected $casts = [
        'future_cancel_date' => 'date',
        'imported_at' => 'datetime',
    ];
}
