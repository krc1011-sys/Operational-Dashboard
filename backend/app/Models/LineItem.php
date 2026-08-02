<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LineItem extends Model
{
    protected $fillable = [
        'marketplace', 'po_id', 'sku_id', 'sku_id_type', 'title', 'model_number',
        'fc_raw', 'fc_code', 'fc_name', 'window_start', 'window_end', 'expected_date',
        'qty_requested', 'qty_accepted', 'unit_cost', 'currency',
        'source_file', 'imported_at', 'imported_by',
    ];

    protected $casts = [
        'window_start' => 'date',
        'window_end' => 'date',
        'expected_date' => 'date',
        'unit_cost' => 'decimal:4',
        'imported_at' => 'datetime',
    ];

    // Native-id join only (marketplace + po_id + sku_id) - never barcode. See DOCUMENTATION.md.
    public function shipmentLines()
    {
        return $this->hasMany(ShipmentLine::class, 'sku_id', 'sku_id')
            ->whereColumn('shipment_lines.marketplace', 'line_items.marketplace')
            ->whereColumn('shipment_lines.po_id', 'line_items.po_id');
    }

    public function cancelledItem()
    {
        return $this->hasOne(CancelledItem::class, 'sku_id', 'sku_id')
            ->whereColumn('cancelled_items.marketplace', 'line_items.marketplace')
            ->whereColumn('cancelled_items.po_id', 'line_items.po_id');
    }
}
