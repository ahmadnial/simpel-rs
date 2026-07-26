<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Traits\FixesSqlServerDates;

class Document extends Model
{
    use HasFactory, SoftDeletes, FixesSqlServerDates;

    // Status constants
    const STATUS_DRAFT          = 'draft';
    const STATUS_DIAJUKAN       = 'diajukan';
    const STATUS_VERIFIKASI     = 'dalam_verifikasi';
    const STATUS_REVISI         = 'revisi';
    const STATUS_MENUNGGU_TTD   = 'menunggu_ttd';
    const STATUS_DITANDATANGANI = 'ditandatangani';
    const STATUS_DIPUBLIKASIKAN = 'dipublikasikan';
    const STATUS_DIARSIPKAN     = 'diarsipkan';
    const STATUS_BATAL          = 'ditolak_batal';

    protected $fillable = [
        'judul', 'document_type_id', 'unit_id', 'pengusul_id',
        'workflow_template_id', 'status', 'current_step',
        'nomor_surat', 'tanggal_surat', 'perihal', 'keterangan',
        'is_rahasia', 'diajukan_at', 'ditandatangani_at',
        'dipublikasikan_at', 'diarsipkan_at', 'hash_final',
    ];

    protected function casts(): array
    {
        return [
            'is_rahasia'         => 'boolean',
            'diajukan_at'        => 'datetime',
            'ditandatangani_at'  => 'datetime',
            'dipublikasikan_at'  => 'datetime',
            'diarsipkan_at'      => 'datetime',
            'tanggal_surat'      => 'date',
            'current_step'       => 'integer',
        ];
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function pengusul()
    {
        return $this->belongsTo(User::class, 'pengusul_id');
    }

    public function workflowTemplate()
    {
        return $this->belongsTo(WorkflowTemplate::class);
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('versi', 'desc');
    }

    public function currentVersion()
    {
        return $this->hasOne(DocumentVersion::class)->where('is_current', true);
    }

    public function verifications()
    {
        return $this->hasMany(DocumentVerification::class)->orderBy('level')->orderBy('created_at');
    }

    public function latestVerification()
    {
        return $this->hasOne(DocumentVerification::class)->latestOfMany();
    }

    public function signature()
    {
        return $this->hasOne(DocumentSignature::class)->latestOfMany();
    }

    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'model')
            ->orderBy('created_at', 'desc');
    }

    // Helpers
    public function isDraft(): bool        { return $this->status === self::STATUS_DRAFT; }
    public function isRevisi(): bool       { return $this->status === self::STATUS_REVISI; }
    public function isVerifikasi(): bool   { return in_array($this->status, [self::STATUS_DIAJUKAN, self::STATUS_VERIFIKASI]); }
    public function isMenungguTtd(): bool  { return $this->status === self::STATUS_MENUNGGU_TTD; }
    public function isSigned(): bool       { return $this->status === self::STATUS_DITANDATANGANI; }
    public function isPublished(): bool    { return $this->status === self::STATUS_DIPUBLIKASIKAN; }
    public function isArchived(): bool     { return $this->status === self::STATUS_DIARSIPKAN; }
    public function isLocked(): bool       { return !in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REVISI]); }

    public function getStatusLabelAttribute(): string
    {
        return [
            self::STATUS_DRAFT          => 'Draft',
            self::STATUS_DIAJUKAN       => 'Diajukan',
            self::STATUS_VERIFIKASI     => 'Dalam Verifikasi',
            self::STATUS_REVISI         => 'Perlu Revisi',
            self::STATUS_MENUNGGU_TTD   => 'Menunggu Tanda Tangan',
            self::STATUS_DITANDATANGANI => 'Ditandatangani',
            self::STATUS_DIPUBLIKASIKAN => 'Dipublikasikan',
            self::STATUS_DIARSIPKAN     => 'Diarsipkan',
            self::STATUS_BATAL          => 'Dibatalkan',
        ][$this->status] ?? ucfirst($this->status);
    }

    public function getStatusColorAttribute(): string
    {
        return [
            self::STATUS_DRAFT          => 'gray',
            self::STATUS_DIAJUKAN       => 'blue',
            self::STATUS_VERIFIKASI     => 'yellow',
            self::STATUS_REVISI         => 'orange',
            self::STATUS_MENUNGGU_TTD   => 'purple',
            self::STATUS_DITANDATANGANI => 'green',
            self::STATUS_DIPUBLIKASIKAN => 'teal',
            self::STATUS_DIARSIPKAN     => 'indigo',
            self::STATUS_BATAL          => 'red',
        ][$this->status] ?? 'gray';
    }
}
