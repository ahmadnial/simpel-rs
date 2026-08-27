<?php

$file = '/Users/user/Documents/Nial-Apps/simpel-rs/app/Services/DocumentService.php';
$content = file_get_contents($file);

// Replace ajukanDokumen and setujui
$pattern = '/\/\*\*\s*\*\s*Ajukan dokumen ke verifikasi\.\s*\*\/\s*public function ajukanDokumen.*?return \$document->fresh\(\);\s*\}\);\s*\}/s';
$replacement = <<<REPLACEMENT
/**
     * Ajukan dokumen ke verifikasi.
     */
    public function ajukanDokumen(\App\Models\Document \$document, int \$verifikatorId): \App\Models\Document
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use (\$document, \$verifikatorId) {
            abort_unless(
                in_array(\$document->status, [\App\Models\Document::STATUS_DRAFT, \App\Models\Document::STATUS_REVISI]),
                403, 'Dokumen tidak dapat diajukan dari status saat ini.'
            );

            \$currentVersion = \$document->currentVersion;
            abort_unless(\$currentVersion, 422, 'Tidak ada file dokumen yang diunggah.');

            // Tentukan workflow template
            \$template = \$document->workflowTemplate
                ?? \$document->documentType->workflowTemplates()->where('is_default', true)->first();

            \$firstStep = \$template?->steps()->first();

            \$this->createVerificationsForStep(\$document, \$currentVersion, \$firstStep, 1, \$verifikatorId);

            \$document->update([
                'status'              => \App\Models\Document::STATUS_DIAJUKAN,
                'workflow_template_id'=> \$template?->id,
                'current_step'        => 1,
                'diajukan_at'         => now(),
            ]);

            \App\Models\AuditLog::catat('ajukan', "Dokumen diajukan ke verifikasi", \$document);
            return \$document->fresh();
        });
    }

    /**
     * Helper membuat verifikasi berdasarkan konfigurasi step.
     */
    private function createVerificationsForStep(\App\Models\Document \$document, \$currentVersion, \$step, \$level, \$defaultVerifikatorId = null)
    {
        if (!\$step) return;
        \$verifiers = [];

        if (\$step->isParallelQuorum()) {
            \$pools = \$step->verifierPool;
            foreach (\$pools as \$pool) {
                if (\$pool->tipe_pool === 'user' && \$pool->user_id) {
                    \$verifiers[] = \$pool->user;
                } elseif (\$pool->tipe_pool === 'role' && \$pool->role_nama) {
                    \$users = \App\Models\User::role(\$pool->role_nama)->get();
                    foreach (\$users as \$u) { \$verifiers[] = \$u; }
                }
            }
        } else {
            if (\$defaultVerifikatorId) {
                \$verifiers[] = \App\Models\User::find(\$defaultVerifikatorId);
            } elseif (\$step->role_nama) {
                \$users = \App\Models\User::role(\$step->role_nama)->get();
                foreach (\$users as \$u) { \$verifiers[] = \$u; }
            }
        }

        \$uniqueVerifiers = collect(\$verifiers)->filter()->unique('id');

        foreach (\$uniqueVerifiers as \$v) {
            \App\Models\DocumentVerification::firstOrCreate([
                'document_id'         => \$document->id,
                'workflow_step_id'    => \$step->id,
                'verifikator_id'      => \$v->id,
                'level'               => \$level,
                'status'              => \App\Models\DocumentVerification::STATUS_MENUNGGU,
            ], [
                'document_version_id' => \$currentVersion->id,
                'batas_waktu'         => now()->addDays(\$step->sla_hari_kerja ?? 2),
            ]);

            \$v->notify(new \App\Notifications\DokumenNotification(
                \$document,
                'diajukan',
                'Antrian Verifikasi Dokumen',
                "Dokumen '{\$document->judul}' memerlukan verifikasi dari Anda.",
                route('verifikasi.index')
            ));
        }
    }

    /**
     * Verifikator menyetujui dokumen.
     */
    public function setujui(\App\Models\DocumentVerification \$verification, ?string \$catatan = null): \App\Models\Document
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use (\$verification, \$catatan) {
            \$document = \$verification->document;

            \$verification->update([
                'status'      => \App\Models\DocumentVerification::STATUS_DISETUJUI,
                'catatan'     => \$catatan,
                'direspon_at' => now(),
            ]);

            \App\Models\AuditLog::catat('setujui', "Dokumen disetujui oleh " . auth()->user()->name, \$document);

            \$step = \$verification->workflowStep;
            if (\$step && \$step->isParallelQuorum()) {
                \$minApproval = \$step->min_approval ?? 1;
                \$approvedCount = \App\Models\DocumentVerification::where('document_id', \$document->id)
                    ->where('workflow_step_id', \$step->id)
                    ->where('status', \App\Models\DocumentVerification::STATUS_DISETUJUI)
                    ->count();

                if (\$approvedCount < \$minApproval) {
                    if (\$document->status === \App\Models\Document::STATUS_DITOLAK_TTD) {
                        \$document->update(['status' => \App\Models\Document::STATUS_VERIFIKASI]);
                    }
                    return \$document->fresh();
                }
            }

            // Jika status sebelumnya ditolak ttd dan quorum terpenuhi, dokumen bisa lanjut
            \$template = \$document->workflowTemplate;
            \$nextVerificationStep = \$template?->steps()
                ->where('urutan', '>', \$verification->level)
                ->where('tipe', 'verifikasi')
                ->first();

            if (\$nextVerificationStep) {
                \$nextLevel = \$nextVerificationStep->urutan;
                \$this->createVerificationsForStep(\$document, \$verification->documentVersion, \$nextVerificationStep, \$nextLevel);

                \$document->update([
                    'status'       => \App\Models\Document::STATUS_VERIFIKASI,
                    'current_step' => \$nextLevel,
                ]);
            } else {
                // Lanjut ke Penandatangan
                \$penandatanganStep = \$template?->steps()->where('tipe', 'penandatangan')->first();
                \$document->update([
                    'status'       => \App\Models\Document::STATUS_MENUNGGU_TTD,
                    'current_step' => \$penandatanganStep?->urutan ?? (\$verification->level + 1),
                ]);
                \App\Models\AuditLog::catat('lolos_verifikasi', "Dokumen lolos semua verifikasi, menunggu TTE", \$document);

                \$penandatangans = \App\Models\User::permission('dokumen.tanda_tangan')->get();
                foreach (\$penandatangans as \$p) {
                    \$p->notify(new \App\Notifications\DokumenNotification(
                        \$document,
                        'menunggu_ttd',
                        'Dokumen Menunggu TTE Sah',
                        "Dokumen '{\$document->judul}' telah disetujui penuh & siap ditandatangani.",
                        route('ttd.index')
                    ));
                }

                \$document->pengusul?->notify(new \App\Notifications\DokumenNotification(
                    \$document,
                    'menunggu_ttd',
                    'Verifikasi Selesai',
                    "Dokumen '{\$document->judul}' lolos verifikasi dan masuk antrian TTE.",
                    route('dokumen.show', \$document)
                ));
            }

            return \$document->fresh();
        });
    }
REPLACEMENT;

$content = preg_replace($pattern, $replacement, $content);

// Insert tolakTandaTangan and turunkanKeVerifikatorBawah at the end (before last bracket)
$additionalMethods = <<<ADDITIONAL

    /**
     * Penandatangan menolak dokumen dan mengembalikan ke verifikator tertinggi.
     */
    public function tolakTandaTangan(\App\Models\Document \$document, string \$alasanTolak): \App\Models\Document
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use (\$document, \$alasanTolak) {
            \$user = auth()->user();

            \$document->update([
                'status'             => \App\Models\Document::STATUS_DITOLAK_TTD,
                'ditolak_ttd_alasan' => \$alasanTolak,
                'ditolak_ttd_at'     => now(),
                'ditolak_ttd_oleh'   => \$user->id,
            ]);

            // Cari verifikasi yang levelnya tertinggi dan disetujui
            \$highestLevel = \App\Models\DocumentVerification::where('document_id', \$document->id)
                ->where('status', \App\Models\DocumentVerification::STATUS_DISETUJUI)
                ->max('level');

            if (\$highestLevel) {
                \$verificationsToReset = \App\Models\DocumentVerification::where('document_id', \$document->id)
                    ->where('level', \$highestLevel)
                    ->where('status', \App\Models\DocumentVerification::STATUS_DISETUJUI)
                    ->get();

                foreach (\$verificationsToReset as \$verif) {
                    \$verif->update([
                        'status'         => \App\Models\DocumentVerification::STATUS_MENUNGGU,
                        'direset_alasan' => "Dikembalikan penandatangan: {\$alasanTolak}",
                        'direset_at'     => now(),
                        'direspon_at'    => null,
                    ]);

                    \$verif->verifikator?->notify(new \App\Notifications\DokumenNotification(
                        \$document,
                        'ditolak_penandatangan',
                        'Dokumen Dikembalikan Penandatangan',
                        "Dokumen '{\$document->judul}' dikembalikan. Catatan: {\$alasanTolak}",
                        route('verifikasi.show', \$verif)
                    ));
                }
            }

            \App\Models\AuditLog::catat('tolak_ttd', "Dikembalikan penandatangan: {\$alasanTolak}", \$document);

            \$document->pengusul?->notify(new \App\Notifications\DokumenNotification(
                \$document,
                'ditolak_penandatangan',
                'Dokumen Dikembalikan Penandatangan',
                "Dokumen '{\$document->judul}' dikembalikan ke tahap verifikasi dengan catatan: {\$alasanTolak}",
                route('dokumen.show', \$document)
            ));

            return \$document->fresh();
        });
    }

    /**
     * Verifikator meneruskan dokumen ke level verifikasi sebelumnya (bawah).
     */
    public function turunkanKeVerifikatorBawah(\App\Models\DocumentVerification \$verification, string \$alasan): \App\Models\Document
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use (\$verification, \$alasan) {
            \$document = \$verification->document;
            
            // Tandai verifikasi ini batal/dikembalikan
            \$verification->update([
                'status'         => \App\Models\DocumentVerification::STATUS_MENUNGGU,
                'direset_alasan' => "Diturunkan sendiri dengan alasan: {\$alasan}",
                'direset_at'     => now(),
            ]);

            // Cari level sebelumnya
            \$lowerLevel = \App\Models\DocumentVerification::where('document_id', \$document->id)
                ->where('level', '<', \$verification->level)
                ->where('status', \App\Models\DocumentVerification::STATUS_DISETUJUI)
                ->max('level');

            if (\$lowerLevel) {
                \$lowerVerifications = \App\Models\DocumentVerification::where('document_id', \$document->id)
                    ->where('level', \$lowerLevel)
                    ->where('status', \App\Models\DocumentVerification::STATUS_DISETUJUI)
                    ->get();

                foreach (\$lowerVerifications as \$lowerVerif) {
                    \$lowerVerif->update([
                        'status'         => \App\Models\DocumentVerification::STATUS_MENUNGGU,
                        'direset_alasan' => "Dikembalikan dari level atas: {\$alasan}",
                        'direset_at'     => now(),
                        'direspon_at'    => null,
                    ]);

                    \$lowerVerif->verifikator?->notify(new \App\Notifications\DokumenNotification(
                        \$document,
                        'dikembalikan_verifikator',
                        'Dokumen Dikembalikan ke Tahap Sebelumnya',
                        "Dokumen '{\$document->judul}' dikembalikan dari tahap selanjutnya. Catatan: {\$alasan}",
                        route('verifikasi.show', \$lowerVerif)
                    ));
                }
                
                // Update step dokumen
                \$document->update(['current_step' => \$lowerLevel]);
            } else {
                // Jika tidak ada level sebelumnya, mungkin minta revisi ke pengusul?
                // Untuk amannya set status ke revisi
                \$document->update(['status' => \App\Models\Document::STATUS_REVISI]);
                \$document->pengusul?->notify(new \App\Notifications\DokumenNotification(
                    \$document,
                    'revisi',
                    'Dokumen Perlu Diperbaiki',
                    "Dokumen '{\$document->judul}' dikembalikan ke pengusul. Catatan: {\$alasan}",
                    route('dokumen.show', \$document)
                ));
            }

            \App\Models\AuditLog::catat('turunkan_verifikasi', "Diturunkan ke level bawah: {\$alasan}", \$document);

            return \$document->fresh();
        });
    }
}
ADDITIONAL;

$content = preg_replace('/\}\s*$/', $additionalMethods, $content);

file_put_contents($file, $content);
echo "DocumentService.php updated.\n";

