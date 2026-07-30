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
    const STATUS_DITARIK        = 'ditarik';
    const STATUS_DIARSIPKAN     = 'diarsipkan';
    const STATUS_BATAL          = 'ditolak_batal';

    protected $fillable = [
        'judul', 'document_type_id', 'unit_id', 'pengusul_id',
        'workflow_template_id', 'status', 'current_step',
        'nomor_surat', 'tanggal_surat', 'perihal', 'keterangan',
        'is_rahasia', 'visibility_scope', 'diajukan_at', 'ditandatangani_at',
        'dipublikasikan_at', 'ditarik_at', 'alasan_penarikan',
        'pengganti_document_id', 'diarsipkan_at', 'hash_final',
    ];

    protected function casts(): array
    {
        return [
            'is_rahasia'         => 'boolean',
            'diajukan_at'        => 'datetime',
            'ditandatangani_at'  => 'datetime',
            'dipublikasikan_at'  => 'datetime',
            'ditarik_at'         => 'datetime',
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

    public function distributions()
    {
        return $this->hasMany(DocumentDistribution::class);
    }

    /**
     * Dokumen pengganti (jika dokumen ini ditarik karena ada pembaharuan).
     */
    public function penggantiDocument()
    {
        return $this->belongsTo(Document::class, 'pengganti_document_id');
    }

    /**
     * Dokumen-dokumen lama yang digantikan oleh dokumen ini.
     */
    public function digantikanOleh()
    {
        return $this->hasMany(Document::class, 'pengganti_document_id');
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
    public function isDitarik(): bool      { return $this->status === self::STATUS_DITARIK; }
    public function isArchived(): bool     { return $this->status === self::STATUS_DIARSIPKAN; }
    public function isLocked(): bool       { return !in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REVISI]); }

    /**
     * Pengecekan hak akses user terhadap dokumen berdasarkan visibility_scope & unit.
     */
    public function isAccessibleBy(User $user): bool
    {
        // Admin / Super Admin selalu punya akses
        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            return true;
        }

        // Pengusul selalu punya akses ke dokumennya sendiri
        if ($this->pengusul_id === $user->id) {
            return true;
        }

        // User dalam rantai verifikasi / penandatangan punya akses
        if ($this->verifications()->where('verifikator_id', $user->id)->exists()) {
            return true;
        }
        if ($this->signature && $this->signature->penandatangan_id === $user->id) {
            return true;
        }

        // Cek visibilitas publikasi
        $scope = $this->visibility_scope ?? ($this->is_rahasia ? 'terbatas' : 'internal');

        return match ($scope) {
            'terbatas' => ($user->unit_id === $this->unit_id),
            'unit'     => ($user->unit_id === $this->unit_id || $this->distributions()->where('unit_id', $user->unit_id)->exists()),
            'internal' => true,
            default    => true,
        };
    }

    /**
     * Scope query filter dokumen yang dapat diakses user di Arsip/Pencarian.
     */
    public function scopeAccessibleBy($query, User $user)
    {
        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            // Pengusul sendiri
            $q->where('pengusul_id', $user->id)
              // Scope internal
              ->orWhere('visibility_scope', 'internal')
              ->orWhere(function ($q2) use ($user) {
                  // Scope unit: unit pengusul atau unit tujuan sebar
                  $q2->where('visibility_scope', 'unit')
                     ->where(function ($q3) use ($user) {
                         $q3->where('unit_id', $user->unit_id)
                            ->orWhereHas('distributions', fn($d) => $d->where('unit_id', $user->unit_id));
                     });
              })
              ->orWhere(function ($q4) use ($user) {
                  // Scope terbatas: hanya unit pengusul
                  $q4->where('visibility_scope', 'terbatas')
                     ->where('unit_id', $user->unit_id);
              });
        });
    }

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
            self::STATUS_DITARIK        => 'Ditarik dari Publikasi',
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
            self::STATUS_DITARIK        => 'orange',
            self::STATUS_DIARSIPKAN     => 'indigo',
            self::STATUS_BATAL          => 'red',
        ][$this->status] ?? 'gray';
    }
}
