<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WorkflowTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'nama', 'document_type_id',
        'deskripsi', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active'  => 'boolean',
        ];
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * Unit/instalasi/tim/komite yang jadi pengecualian cakupan template ini.
     * Kosong (tidak ada baris pivot) = berlaku untuk semua unit (perilaku default).
     */
    public function units()
    {
        return $this->belongsToMany(Unit::class, 'workflow_template_units');
    }

    public function steps()
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('urutan');
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }
}

