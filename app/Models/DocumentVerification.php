<?php

namespace App\Models;

use App\Models\Traits\FixesSqlServerDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentVerification extends Model
{
    use FixesSqlServerDates, HasFactory;

    const STATUS_MENUNGGU = 'menunggu';

    const STATUS_DISETUJUI = 'disetujui';

    const STATUS_REVISI = 'revisi';

    const STATUS_DITOLAK = 'ditolak';

    const STATUS_DIBATALKAN = 'batal';

    const STATUS_DIKEMBALIKAN = 'dikembalikan';

    const REASON_SUBMITTED = 'submitted';

    const REASON_RESUBMITTED = 'resubmitted';

    const REASON_ADVANCED = 'advanced';

    const REASON_RETURNED_BY_VERIFIER = 'returned_by_verifier';

    const REASON_RETURNED_BY_SIGNER = 'returned_by_signer';

    protected $fillable = [
        'document_id', 'document_version_id', 'workflow_step_id',
        'verifikator_id', 'level', 'verification_round', 'activation_reason',
        'reopened_from_verification_id', 'closed_by_verification_id', 'decided_by_user_id',
        'status', 'catatan',
        'batas_waktu', 'direspon_at',
        'direset_alasan', 'direset_at',
    ];

    protected function casts(): array
    {
        return [
            // SQL Server mengembalikan kolom unsignedBigInteger (numeric) sebagai
            // string. Normalisasi FK ini penting karena otorisasi membandingkan
            // identitas secara ketat untuk mencegah pengambilalihan tiket.
            'document_id' => 'integer',
            'document_version_id' => 'integer',
            'workflow_step_id' => 'integer',
            'verifikator_id' => 'integer',
            'batas_waktu' => 'datetime',
            'direspon_at' => 'datetime',
            'direset_at' => 'datetime',
            'level' => 'integer',
            'verification_round' => 'integer',
            'reopened_from_verification_id' => 'integer',
            'closed_by_verification_id' => 'integer',
            'decided_by_user_id' => 'integer',
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

    public function reopenedFrom()
    {
        return $this->belongsTo(self::class, 'reopened_from_verification_id');
    }

    public function closingDecision()
    {
        return $this->belongsTo(self::class, 'closed_by_verification_id');
    }

    public function decisionMaker()
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function resolvedDecision(): ?self
    {
        if ($this->closingDecision) {
            return $this->closingDecision;
        }

        return ! $this->isMenunggu() && ! $this->isDibatalkan() ? $this : null;
    }

    public function resolvedDecisionMaker(): ?User
    {
        $decision = $this->resolvedDecision();

        return $decision?->decisionMaker ?? $decision?->verifikator;
    }

    public function closureLabel(): string
    {
        return $this->closed_by_verification_id ? 'Selesai melalui keputusan verifikator lain' : 'Penugasan Ditutup';
    }

    public function reopenedTickets()
    {
        return $this->hasMany(self::class, 'reopened_from_verification_id');
    }

    public function scopeActionable(Builder $query, ?int $actorId = null): Builder
    {
        return $query
            ->whereNotExists(function ($prior) use ($actorId) {
                $prior->selectRaw('1')->from('document_verifications as prior_level')
                    ->whereColumn('prior_level.document_id', 'document_verifications.document_id')
                    ->whereColumn('prior_level.document_version_id', 'document_verifications.document_version_id')
                    ->where(function ($identity) use ($actorId) {
                        $identity->whereColumn('prior_level.verifikator_id', 'document_verifications.verifikator_id');
                        if ($actorId !== null) {
                            $identity->orWhere('prior_level.verifikator_id', $actorId);
                        }
                    })
                    ->whereColumn('prior_level.level', '<', 'document_verifications.level');
            })
            ->where('document_verifications.status', self::STATUS_MENUNGGU)
            ->where('document_verifications.verification_round', '=', function ($activeRound) {
                $activeRound->selectRaw('MAX(active_verifications.verification_round)')
                    ->from('document_verifications as active_verifications')
                    ->whereColumn('active_verifications.document_id', 'document_verifications.document_id')
                    ->whereColumn('active_verifications.document_version_id', 'document_verifications.document_version_id')
                    ->whereColumn('active_verifications.level', 'document_verifications.level')
                    ->where('active_verifications.status', self::STATUS_MENUNGGU);
            })
            ->whereHas('workflowStep', function (Builder $step): void {
                $step
                    ->where('workflow_steps.tipe', 'verifikasi')
                    ->whereColumn('workflow_steps.urutan', 'document_verifications.level')
                    ->where('workflow_steps.workflow_template_id', '=', function ($documentTemplate) {
                        $documentTemplate->select('workflow_template_id')
                            ->from('documents')
                            ->whereColumn('documents.id', 'document_verifications.document_id')
                            ->limit(1);
                    });
            })
            ->whereHas('version', fn (Builder $version) => $version->where('is_current', true))
            ->whereHas('document', function (Builder $document): void {
                $document
                    ->whereIn('status', [
                        Document::STATUS_DIAJUKAN,
                        Document::STATUS_VERIFIKASI,
                        Document::STATUS_DITOLAK_TTD,
                    ])
                    ->whereColumn('documents.current_step', 'document_verifications.level');
            });
    }

    public function verifikator()
    {
        return $this->belongsTo(User::class, 'verifikator_id');
    }

    public function isMenunggu(): bool
    {
        return $this->status === self::STATUS_MENUNGGU;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_DISETUJUI;
    }

    public function isRevisi(): bool
    {
        return $this->status === self::STATUS_REVISI;
    }

    public function isDibatalkan(): bool
    {
        return $this->status === self::STATUS_DIBATALKAN;
    }

    public function isDikembalikan(): bool
    {
        return $this->status === self::STATUS_DIKEMBALIKAN;
    }

    public function isActionable(?int $actorId = null): bool
    {
        $document = $this->relationLoaded('document') ? $this->document : $this->document()->with('currentVersion')->first();
        $step = $this->relationLoaded('workflowStep') ? $this->workflowStep : $this->workflowStep()->first();
        $activeRound = self::where('document_id', $this->document_id)
            ->where('document_version_id', $this->document_version_id)
            ->where('level', $this->level)
            ->where('status', self::STATUS_MENUNGGU)
            ->max('verification_round');

        return $this->isMenunggu()
            && ! $this->hasLowerLevelAssignment()
            && ($actorId === null || ! $this->hasLowerLevelAssignment($actorId))
            && $this->verification_round === (int) $activeRound
            && (int) $document?->currentVersion?->id === $this->document_version_id
            && (int) $document?->current_step === $this->level
            && (int) $step?->workflow_template_id === (int) $document?->workflow_template_id
            && $step->tipe === 'verifikasi'
            && (int) $step->urutan === $this->level
            && in_array($document->status, [
                Document::STATUS_DIAJUKAN,
                Document::STATUS_VERIFIKASI,
                Document::STATUS_DITOLAK_TTD,
            ], true);
    }

    public function isOverdue(): bool
    {
        return $this->isMenunggu() && $this->batas_waktu && now()->gt($this->batas_waktu);
    }

    public function hasLowerLevelAssignment(?int $actorId = null): bool
    {
        return self::where('document_id', $this->document_id)
            ->where('document_version_id', $this->document_version_id)
            ->where('verifikator_id', $actorId ?? $this->verifikator_id)
            ->where('level', '<', $this->level)
            ->exists();
    }
}
