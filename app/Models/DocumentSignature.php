<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use App\Models\Traits\FixesSqlServerDates;

class DocumentSignature extends Model
{
    use HasFactory, FixesSqlServerDates;

    protected $fillable = [
        'document_id', 'document_version_id', 'penandatangan_id',
        'delegasi_id', 'metode_tte', 'hash_dokumen', 'qr_token',
        'file_signed_path', 'metadata_tte', 'ditandatangani_at',
    ];

    protected function casts(): array
    {
        return [
            'ditandatangani_at' => 'datetime',
            'metadata_tte'      => 'array',
        ];
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function penandatangan()
    {
        return $this->belongsTo(User::class, 'penandatangan_id');
    }

    public function delegasi()
    {
        return $this->belongsTo(User::class, 'delegasi_id');
    }

    public function getVerifikasiUrlAttribute(): string
    {
        return route('public.verify', $this->qr_token);
    }
}
