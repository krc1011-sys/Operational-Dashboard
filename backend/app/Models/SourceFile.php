<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceFile extends Model
{
    protected $fillable = ['filename', 'marketplace', 'doc_type', 'row_count', 'uploaded_by', 'imported_at'];

    protected $casts = ['imported_at' => 'datetime'];
}
