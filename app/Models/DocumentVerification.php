<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use App\Models\Traits\FixesSqlServerDates;

class DocumentVerification extends Model
{
    use HasFactory, FixesSqlServerDates;

    const STATUS_MENUNGGU   = 'menunggu';
    const STATUS_DISETUJUI  = 'disetujui';
    const STATUS_REVISI     = 'revisi';
    const STATUS_DITOLAK    = 'ditolak';
    const STATUS_DIBATALKAN = 'batal';

    protected $fillable = [
        'document_id', 'document_version_id', 'workflow_step_id',
        'verifikator_id', 'level', 'status', 'catatan',
        'batas_waktu', 'direspon_at',
        'direset_alasan', 'direset_at',
    ];

    protected function casts(): array
    {
        return [
            // SQL Server mengembalikan kolom unsignedBigInteger (numeric) sebagai
            // string. Normalisasi FK ini penting karena otorisasi membandingkan
            // identitas secara ketat untuk mencegah pengambilalihan tiket.
            'document_id'         => 'integer',
            'document_version_id' => 'integer',
            'workflow_step_id'    => 'integer',
            'verifikator_id'      => 'integer',
            'batas_waktu'  => 'datetime',
            'direspon_at'  => 'datetime',
            'direset_at'   => 'datetime',
            'level'        => 'integer',
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

    public function workflowStep()
    {
        return $this->belongsTo(WorkflowStep::class);
    }

    public function verifikator()
    {
        return $this->belongsTo(User::class, 'verifikator_id');
    }

    public function isMenunggu(): bool   { return $this->status === self::STATUS_MENUNGGU; }
    public function isApproved(): bool   { return $this->status === self::STATUS_DISETUJUI; }
    public function isRevisi(): bool     { return $this->status === self::STATUS_REVISI; }
    public function isDibatalkan(): bool { return $this->status === self::STATUS_DIBATALKAN; }
    public function isOverdue(): bool
    {
        return $this->isMenunggu() && $this->batas_waktu && now()->gt($this->batas_waktu);
    }
}
