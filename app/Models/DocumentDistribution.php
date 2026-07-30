<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DocumentDistribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'unit_id',
        'catatan',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
