<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DocumentVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id', 'versi', 'file_path', 'file_pdf_path',
        'file_name', 'file_size', 'uploaded_by', 'catatan', 'is_current',
    ];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'versi' => 'integer'];
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifications()
    {
        return $this->hasMany(DocumentVerification::class);
    }

    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size ?? 0;
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
