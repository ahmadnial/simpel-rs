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
        'signature_evidence_id', 'otp_challenge_id', 'assurance_profile',
    ];

    protected function casts(): array
    {
        return [
            'document_id'       => 'integer',
            'document_version_id' => 'integer',
            'penandatangan_id'  => 'integer',
            'delegasi_id'       => 'integer',
            'signature_evidence_id' => 'integer',
            'otp_challenge_id' => 'integer',
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

    public function principal()
    {
        return $this->belongsTo(User::class, 'delegasi_id');
    }

    public function evidence()
    {
        return $this->belongsTo(SignatureEvidence::class, 'signature_evidence_id');
    }

    public function otpChallenge()
    {
        return $this->belongsTo(SignatureOtpChallenge::class);
    }

    public function getVerifikasiUrlAttribute(): string
    {
        return route('public.verify', $this->qr_token);
    }
}
