<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentLine extends Model
{
    protected $fillable = [
        'marketplace', 'po_id', 'sku_id', 'stage', 'qty', 'carton',
        'shipment_name', 'shipment_date', 'model_number', 'title',
        'source_file', 'imported_at', 'imported_by',
    ];

    protected $casts = [
        'shipment_date' => 'date',
        'imported_at' => 'datetime',
    ];
}
