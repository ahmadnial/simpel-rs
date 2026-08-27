<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentVerification;
use App\Models\DocumentVersion;
use App\Models\NumberingSequence;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class DocumentService
{
    /**
     * Upload dokumen baru dan simpan versi pertama.
     */
    public function uploadDraft(array $data, ?UploadedFile $file = null): Document
    {
        return DB::transaction(function () use ($data, $file) {
            $user = auth()->user();

            $isRahasia = $data['is_rahasia'] ?? false;
            $document = Document::create([
                'judul'               => $data['judul'],
                'document_type_id'    => $data['document_type_id'],
                'unit_id'             => $data['unit_id'] ?? $user->unit_id,
                'pengusul_id'         => $user->id,
                'workflow_template_id'=> $data['workflow_template_id'] ?? null,
                'perihal'             => $data['perihal'] ?? null,
                'keterangan'          => $data['keterangan'] ?? null,
                'is_rahasia'          => $isRahasia,
                // Selalu mulai dari 'terbatas' (hanya unit sendiri yang bisa lihat), apapun status
                // is_rahasia — dokumen yang belum diverifikasi/dipublikasikan tidak semestinya
                // langsung terlihat semua unit. Visibilitas yang lebih luas baru dibuka lewat
                // publikasi() setelah dokumen final & sah. Ini juga jadi dasar "Arsip Internal
                // Unit": dokumen yang tidak pernah diajukan ke verifikator tetap 'terbatas' selamanya.
                'visibility_scope'    => 'terbatas',
                'status'              => Document::STATUS_DRAFT,
            ]);

            if ($file) {
                $this->simpanVersi($document, $file, $data['catatan'] ?? 'Upload awal');
            }

            AuditLog::catat('upload_draft', "Dokumen baru dibuat: {$document->judul}", $document);

            return $document;
        });
    }

    /**
     * Simpan versi baru dokumen.
     */
    public function simpanVersi(Document $document, UploadedFile $file, ?string $catatan = null): DocumentVersion
    {
        // Mark versi lama sebagai bukan current
        $document->versions()->update(['is_current' => false]);

        $versiTerbaru = ($document->versions()->max('versi') ?? 0) + 1;
        $path = $file->store("documents/{$document->id}", 'local');

        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'versi'       => $versiTerbaru,
            'file_path'   => $path,
            'file_name'   => $file->getClientOriginalName(),
            'file_size'   => $file->getSize(),
            'uploaded_by' => auth()->id(),
            'catatan'     => $catatan,
            'is_current'  => true,
        ]);

        AuditLog::catat('upload_versi', "Versi {$versiTerbaru} diunggah untuk dokumen: {$document->judul}", $document);

        return $version;
    }

    /**
     * Ajukan dokumen ke verifikasi.
     */
    public function ajukanDokumen(Document $document, array $verifikatorIds): Document
    {
        return DB::transaction(function () use ($document, $verifikatorIds) {
            abort_unless(
                in_array($document->status, [Document::STATUS_DRAFT, Document::STATUS_REVISI]),
                403, 'Dokumen tidak dapat diajukan dari status saat ini.'
            );

            $currentVersion = $document->currentVersion;
            abort_unless($currentVersion, 422, 'Tidak ada file dokumen yang diunggah.');

            $template = $document->workflowTemplate ?? $this->resolveWorkflowTemplate($document->documentType, $document->unit_id);

            abort_unless($template, 422, 'Template alur kerja (workflow) belum dikonfigurasi untuk jenis naskah ini.');
            // steps() sudah orderBy('urutan') bawaan relasi — jangan tambah orderBy lagi di sini,
            // SQL Server (sqlsrv) menolak ORDER BY dengan kolom yang sama dua kali (error 20018).
            $firstStep = $template->steps()->first();
            abort_unless($firstStep, 422, 'Langkah verifikasi belum dikonfigurasi pada workflow ini.');

            // Batalkan antrian verifikasi lama yang masih berstatus menunggu (jika pengajuan ulang dari revisi)
            DocumentVerification::where('document_id', $document->id)
                ->where('status', DocumentVerification::STATUS_MENUNGGU)
                ->update(['status' => DocumentVerification::STATUS_DIBATALKAN]);

            // visibility_scope TETAP 'terbatas' selama proses verifikasi+TTE berjalan — orang di
            // luar rantai verifikasi (bukan pengusul/verifikator/penandatangan/admin) memang belum
            // perlu lihat dokumen yang belum final. isAccessibleBy() sudah otomatis meng-izinkan
            // pengusul & setiap verifikator/penandatangan yang ditugaskan terlepas dari scope ini.
            // Visibilitas baru dilebarkan secara sadar lewat publikasi() setelah dokumen sah.

            $this->createVerificationsForStep($document, $currentVersion, $firstStep, 1, $verifikatorIds);

            $document->update([
                'status'              => Document::STATUS_DIAJUKAN,
                'workflow_template_id'=> $template->id,
                'current_step'        => 1,
                'diajukan_at'         => now(),
            ]);

            AuditLog::catat('ajukan', "Dokumen diajukan ke verifikasi", $document);

            return $document->fresh();
        });
    }

    /**
     * Resolusi Template Workflow default untuk jenis naskah dokumen ini.
     *
     * Isolasi alur utama tetap PER JENIS NASKAH (document_type) — satu template default
     * berlaku untuk semua unit/instalasi/tim/komite secara global. Cakupan unit pada template
     * (WorkflowTemplate::units(), pivot many-to-many) hanyalah PENGECUALIAN OPSIONAL: kalau ada
     * template lain untuk jenis naskah yang sama yang sengaja dibatasi ke unit tertentu dan unit
     * dokumen ini termasuk di dalamnya, template yang lebih spesifik itu menang atas template
     * default global. Hanya template aktif (is_active=true) yang dipertimbangkan.
     */
    private function resolveWorkflowTemplate(\App\Models\DocumentType $documentType, int $unitId): ?WorkflowTemplate
    {
        $candidates = $documentType->workflowTemplates()
            ->where('is_active', true)
            ->with('units')
            ->get();

        $unitSpecific = $candidates->filter(
            fn (WorkflowTemplate $t) => $t->units->contains('id', $unitId)
        );

        if ($unitSpecific->isNotEmpty()) {
            // Kalau admin tidak sengaja membuat >1 template yang cakupan unitnya tumpang tindih,
            // utamakan yang ditandai default; kalau tidak ada, pilih deterministik (id terkecil)
            // supaya tidak diam-diam berbeda tiap request.
            return $unitSpecific->firstWhere('is_default', true) ?? $unitSpecific->sortBy('id')->first();
        }

        // Fallback: template tanpa cakupan unit sama sekali (berlaku untuk semua unit).
        return $candidates->filter(fn (WorkflowTemplate $t) => $t->units->isEmpty())
            ->firstWhere('is_default', true);
    }

    /**
     * Info tahap 1 workflow untuk sebuah jenis naskah + unit (dipakai form upload dokumen untuk
     * menentukan apakah picker "Ajukan ke Asesor Internal" perlu ditampilkan atau tidak).
     *
     * - 'configured' = false → belum ada Template Workflow aktif untuk kombinasi ini sama sekali.
     * - 'needsManual' = true → tahap 1 mode serial TANPA role_nama: TIDAK ada pool otomatis,
     *   pengusul WAJIB pilih manual verifikator (asesor_internal) di form upload.
     * - 'needsManual' = false (tapi configured=true) → tahap 1 sudah otomatis (mode parallel dengan
     *   pool, atau serial dengan role_nama terisi) — pilihan manual pengusul akan diabaikan, jadi
     *   form tidak perlu menampilkan picker sama sekali, cukup tombol "Ajukan".
     */
    public function getFirstStepInfo(\App\Models\DocumentType $documentType, int $unitId): array
    {
        $template = $this->resolveWorkflowTemplate($documentType, $unitId);
        $firstStep = $template?->steps()->first();

        if (!$firstStep) {
            return ['configured' => false, 'needsManual' => false, 'stepName' => null];
        }

        $needsManual = $firstStep->mode_verifikasi === 'serial' && empty($firstStep->role_nama);

        return [
            'configured'  => true,
            'needsManual' => $needsManual,
            'stepName'    => $firstStep->nama_tahap,
        ];
    }

    /**
     * Pratinjau lengkap rantai alur (semua tahap, urut) untuk sebuah jenis naskah + unit —
     * dipakai form pengajuan dokumen supaya pengusul tahu siapa saja yang akan memeriksa &
     * menandatangani SEBELUM mengklik ajukan, bukan baru tahu setelah diajukan.
     */
    public function getWorkflowChainPreview(\App\Models\DocumentType $documentType, int $unitId): array
    {
        $template = $this->resolveWorkflowTemplate($documentType, $unitId);

        if (!$template) {
            return ['configured' => false, 'steps' => []];
        }

        $steps = $template->steps()->with('verifierPool.user.unit')->get();

        $verifCounter = 0;
        $signCounter = 0;

        $stepsPreview = $steps->values()->map(function ($step, $idx) use (&$verifCounter, &$signCounter) {
            $manual = $idx === 0 && $step->mode_verifikasi === 'serial' && empty($step->role_nama);

            $names = collect();

            if (!$manual) {
                if ($step->isParallelQuorum()) {
                    foreach ($step->verifierPool as $pool) {
                        if ($pool->tipe_pool === 'user' && $pool->user) {
                            $names->push($this->formatPersonLabel($pool->user));
                        } elseif ($pool->tipe_pool === 'role' && $pool->role_nama) {
                            \App\Models\User::role($pool->role_nama)->where('is_active', true)->get()
                                ->each(fn ($u) => $names->push($this->formatPersonLabel($u)));
                        }
                    }
                } elseif ($step->role_nama) {
                    \App\Models\User::role($step->role_nama)->where('is_active', true)->get()
                        ->each(fn ($u) => $names->push($this->formatPersonLabel($u)));
                }
            }

            $names = $names->filter()->unique()->values();
            $min = $step->min_approval ?? 1;

            if ($manual) {
                $who = 'Dipilih pengusul saat pengajuan';
            } elseif ($names->isEmpty()) {
                $who = 'Belum ada pejabat ditugaskan';
            } elseif ($step->isParallelQuorum() && $min < $names->count()) {
                $who = "Min. {$min} dari " . $names->count() . ' pejabat: ' . $names->join(', ');
            } else {
                $who = $names->join(' / ');
            }

            if ($step->isPenandatangan()) {
                $signCounter++;
                $label = $signCounter > 1 ? "Penandatangan {$signCounter}" : 'Penandatangan';
            } else {
                $verifCounter++;
                $label = "Verifikator {$verifCounter}";
            }

            return [
                'label'      => $label,
                'nama_tahap' => $step->nama_tahap,
                'tipe'       => $step->tipe,
                'who'        => $who,
                'manual'     => $manual,
            ];
        })->values()->all();

        return ['configured' => !empty($stepsPreview), 'steps' => $stepsPreview];
    }

    private function formatPersonLabel(\App\Models\User $user): string
    {
        $sub = $user->jabatan ?: $user->unit?->nama;

        return $sub ? "{$user->name} ({$sub})" : $user->name;
    }

    /**
     * Helper membuat verifikasi berdasarkan konfigurasi step.
     */
    private function createVerificationsForStep(Document $document, $currentVersion, $step, $level, array $defaultVerifikatorIds = [])
    {
        if (!$step) return;
        $verifiers = [];

        if ($step->isParallelQuorum()) {
            $pools = $step->verifierPool;
            foreach ($pools as $pool) {
                if ($pool->tipe_pool === 'user' && $pool->user_id) {
                    if ($pool->user) $verifiers[] = $pool->user;
                } elseif ($pool->tipe_pool === 'role' && $pool->role_nama) {
                    $users = \App\Models\User::role($pool->role_nama)->where('is_active', true)->get();
                    foreach ($users as $u) { $verifiers[] = $u; }
                }
            }
        } else {
            if (!empty($defaultVerifikatorIds)) {
                // Multi-verifikator "salah satu approve = sah": setiap ID yang dipilih pengusul
                // divalidasi kelayakannya sendiri-sendiri, lalu semua dapat tiket di level yang
                // sama — begitu satu approve, sisanya otomatis dibatalkan (lihat setujui()).
                foreach ($defaultVerifikatorIds as $verifikatorId) {
                    $target = \App\Models\User::find($verifikatorId);

                    // Cegah pengusul menugaskan dirinya sendiri (atau user tanpa role
                    // 'asesor_internal') sebagai verifikator — mencegah self-approval, dan
                    // memastikan pool "salah satu approve = sah" cuma diisi asesor internal
                    // yang sah, bukan sembarang pemegang permission dokumen.verifikasi.
                    $isEligibleVerifikator = $target
                        && $target->is_active
                        && $target->id !== $document->pengusul_id
                        && $target->hasRole('asesor_internal');

                    abort_unless(
                        $isEligibleVerifikator,
                        422,
                        'Verifikator yang dipilih tidak valid, tidak aktif, atau bukan Asesor Internal.'
                    );

                    $verifiers[] = $target;
                }
            } elseif ($step->role_nama) {
                $users = \App\Models\User::role($step->role_nama)->where('is_active', true)->get();
                foreach ($users as $u) { $verifiers[] = $u; }
            }
        }

        $uniqueVerifiers = collect($verifiers)->filter()->unique('id');

        abort_if(
            $uniqueVerifiers->isEmpty(),
            422,
            "Tidak ada pejabat/pengguna yang ditugaskan untuk verifikasi pada tahap: '{$step->nama_tahap}' (Level {$level}). Harap hubungi Administrator untuk penyesuaian Master Data."
        );

        foreach ($uniqueVerifiers as $v) {
            $verif = DocumentVerification::firstOrCreate([
                'document_id'         => $document->id,
                'document_version_id' => $currentVersion->id,
                'workflow_step_id'    => $step->id,
                'verifikator_id'      => $v->id,
                'level'               => $level,
                'status'              => DocumentVerification::STATUS_MENUNGGU,
            ], [
                'batas_waktu'         => now()->addDays($step->sla_hari_kerja ?? 2),
            ]);

            $v->notify(new \App\Notifications\DokumenNotification(
                $document,
                'diajukan',
                'Antrian Verifikasi Dokumen',
                "Dokumen '{$document->judul}' memerlukan verifikasi dari Anda.",
                route('verifikasi.index')
            ));
        }
    }

    /**
     * Verifikator menyetujui dokumen.
     */
    public function setujui(DocumentVerification $verification, ?string $catatan = null): Document
    {
        return DB::transaction(function () use ($verification, $catatan) {
            $document = $verification->document;
            $currentVersionId = $document->currentVersion?->id ?? $verification->document_version_id;

            $verification->update([
                'status'      => DocumentVerification::STATUS_DISETUJUI,
                'catatan'     => $catatan,
                'direspon_at' => now(),
            ]);

            AuditLog::catat('setujui', "Dokumen disetujui oleh " . auth()->user()->name, $document);

            $step = $verification->workflowStep;
            if ($step && $step->isParallelQuorum()) {
                $minApproval = $step->min_approval ?? 1;
                // lockForUpdate mengunci baris-baris ini selama transaksi agar dua verifikator yang
                // approve nyaris bersamaan tidak sama-sama membaca count di bawah kuorum lalu
                // sama-sama meloloskan step (race condition pada quorum check).
                $approvedCount = DocumentVerification::where('document_id', $document->id)
                    ->where('document_version_id', $currentVersionId)
                    ->where('workflow_step_id', $step->id)
                    ->where('status', DocumentVerification::STATUS_DISETUJUI)
                    ->lockForUpdate()
                    ->count();

                if ($approvedCount < $minApproval) {
                    if ($document->status === Document::STATUS_DITOLAK_TTD) {
                        $document->update(['status' => Document::STATUS_VERIFIKASI]);
                    }
                    return $document->fresh();
                }
            }

            // Bersihkan sisa tiket verifikasi di level ini yang masih 'menunggu' (karena kuorum/syarat sudah terpenuhi)
            DocumentVerification::where('document_id', $document->id)
                ->where('document_version_id', $currentVersionId)
                ->where('workflow_step_id', $step?->id)
                ->where('level', $verification->level)
                ->where('status', DocumentVerification::STATUS_MENUNGGU)
                ->update(['status' => DocumentVerification::STATUS_DIBATALKAN]);

            // Advance step
            $template = $document->workflowTemplate;
            // steps() sudah orderBy('urutan') bawaan relasi — jangan tambah orderBy lagi di sini,
            // SQL Server (sqlsrv) menolak ORDER BY dengan kolom yang sama dua kali (error 20018).
            $nextVerificationStep = $template?->steps()
                ->where('urutan', '>', $verification->level)
                ->where('tipe', 'verifikasi')
                ->first();

            if ($nextVerificationStep) {
                $nextLevel = $nextVerificationStep->urutan;
                $this->createVerificationsForStep($document, $document->currentVersion, $nextVerificationStep, $nextLevel);

                $document->update([
                    'status'       => Document::STATUS_VERIFIKASI,
                    'current_step' => $nextLevel,
                ]);
            } else {
                // Semua verifikasi selesai → Menunggu TTD Direktur / Penandatangan
                $penandatanganStep = $template?->steps()->where('tipe', 'penandatangan')->first();
                $document->update([
                    'status'       => Document::STATUS_MENUNGGU_TTD,
                    'current_step' => $penandatanganStep?->urutan ?? ($verification->level + 1),
                ]);
                AuditLog::catat('lolos_verifikasi', "Dokumen lolos semua verifikasi, menunggu TTE Direktur", $document);

                // Notifikasi Penandatangan: filter berdasarkan role spesifik jika terdefinisi
                $targetPenandatangans = collect();
                if ($penandatanganStep && $penandatanganStep->role_nama) {
                    $targetPenandatangans = \App\Models\User::role($penandatanganStep->role_nama)->where('is_active', true)->get();
                }
                
                if ($targetPenandatangans->isEmpty()) {
                    $targetPenandatangans = \App\Models\User::permission('dokumen.tanda_tangan')->where('is_active', true)->get();
                }

                foreach ($targetPenandatangans as $p) {
                    $p->notify(new \App\Notifications\DokumenNotification(
                        $document,
                        'menunggu_ttd',
                        'Dokumen Menunggu TTE Sah',
                        "Dokumen '{$document->judul}' telah disetujui penuh & siap ditandatangani.",
                        route('ttd.index')
                    ));
                }

                // Notifikasi Pengusul
                $document->pengusul?->notify(new \App\Notifications\DokumenNotification(
                    $document,
                    'menunggu_ttd',
                    'Verifikasi Dokumen Selesai',
                    "Dokumen '{$document->judul}' telah lolos verifikasi dan sedang dalam antrian TTE Direktur.",
                    route('dokumen.show', $document)
                ));
            }

            return $document->fresh();
        });
    }

    /**
     * Verifikator meminta revisi.
     */
    public function mintaRevisi(DocumentVerification $verification, string $catatan): Document
    {
        return DB::transaction(function () use ($verification, $catatan) {
            $document = $verification->document;

            $verification->update([
                'status'      => DocumentVerification::STATUS_REVISI,
                'catatan'     => $catatan,
                'direspon_at' => now(),
            ]);

            $document->update(['status' => Document::STATUS_REVISI]);

            AuditLog::catat('minta_revisi', "Revisi diminta: {$catatan}", $document);
            $document->pengusul?->notify(new \App\Notifications\DokumenNotification(
                $document,
                'revisi',
                'Dokumen Perlu Revisi',
                "Dokumen '{$document->judul}' memerlukan perbaikan. Catatan: {$catatan}",
                route('dokumen.show', $document)
            ));

            return $document->fresh();
        });
    }

    /**
     * Tanda tangan elektronik internal (hash SHA-256 + QR Code).
     */
    public function tandaTangani(Document $document, string $otpInput): Document
    {
        return DB::transaction(function () use ($document, $otpInput) {
            $user = auth()->user();

            // Validasi OTP
            abort_unless($user->isOtpValid($otpInput), 422, 'OTP tidak valid atau sudah kadaluarsa.');
            abort_unless($document->status === Document::STATUS_MENUNGGU_TTD, 403, 'Dokumen tidak dalam status menunggu tanda tangan.');
            $this->assertAuthorizedSigner($document, $user);

            $currentVersion = $document->currentVersion;
            $filePath = $this->ensureDocxFileExists($document, $currentVersion);

            // Hitung hash SHA-256 dokumen
            $hash = hash_file('sha256', $filePath);
            $qrToken = Str::uuid()->toString();

            // Buat nomor surat (atomic)
            $nomor = $this->generateNomorSurat($document);

            // Simpan record TTE
            DocumentSignature::create([
                'document_id'         => $document->id,
                'document_version_id' => $currentVersion->id,
                'penandatangan_id'    => $user->id,
                'metode_tte'          => 'internal',
                'hash_dokumen'        => $hash,
                'qr_token'            => $qrToken,
                'ditandatangani_at'   => now(),
                'metadata_tte'        => [
                    'signer_name'  => $user->name,
                    'signer_role'  => $user->jabatan ?? $user->getRoleNames()->first() ?? 'Penandatangan',
                    'signed_at'    => now()->toIso8601String(),
                ],
            ]);

            $document->update([
                'status'           => Document::STATUS_DITANDATANGANI,
                'nomor_surat'      => $nomor,
                'tanggal_surat'    => now()->toDateString(),
                'hash_final'       => $hash,
                'ditandatangani_at'=> now(),
            ]);

            // Invalidate OTP setelah digunakan
            $user->update(['otp_code' => null, 'otp_expires_at' => null]);

            AuditLog::catat('tanda_tangan', "Dokumen ditandatangani, nomor: {$nomor}", $document, [], ['nomor_surat' => $nomor]);

            $document->pengusul?->notify(new \App\Notifications\DokumenNotification(
                $document,
                'ditandatangani',
                'Dokumen Berhasil Di-TTE Sah',
                "Dokumen '{$document->judul}' telah resmi ditandatangani secara elektronik dengan Nomor: {$nomor}",
                route('dokumen.show', $document)
            ));

            return $document->fresh();
        });
    }

    /**
     * Validasi wewenang penandatangan (termasuk delegasi Plt/Plh) untuk sebuah dokumen.
     * Dipakai bersama oleh tandaTangani() dan tolakTandaTangan() agar aturan otorisasi
     * tidak bisa berbeda/lupa disinkronkan antara aksi tanda tangan dan aksi tolak.
     */
    private function assertAuthorizedSigner(Document $document, \App\Models\User $user): void
    {
        $signerRoles = $user->getRoleNames()->toArray();
        if ($delegated = $user->activeDelegation()) {
            if ($delegated->pejabat) {
                $signerRoles = array_unique(array_merge($signerRoles, $delegated->pejabat->getRoleNames()->toArray()));
            }
        }

        $isAuthorizedSigner = $user->hasRole('super_admin') ||
            ($document->workflowTemplate?->steps()
                ->where('tipe', 'penandatangan')
                ->whereIn('role_nama', $signerRoles)
                ->exists() ?? false);

        abort_unless($isAuthorizedSigner, 403, 'Anda bukan penandatangan yang sah untuk dokumen ini.');
    }

    /**
     * Publikasikan dokumen.
     */
    public function publikasi(Document $document, array $data = []): Document
    {
        abort_unless($document->status === Document::STATUS_DITANDATANGANI, 403, 'Hanya dokumen yang sudah ditandatangani yang dapat dipublikasikan.');

        return DB::transaction(function () use ($document, $data) {
            $scope = $data['visibility_scope'] ?? ($document->is_rahasia ? 'terbatas' : 'internal');

            $document->update([
                'status'            => Document::STATUS_DIPUBLIKASIKAN,
                'dipublikasikan_at' => now(),
                'visibility_scope'  => $scope,
            ]);

            // Hapus distribusi lama jika ada
            $document->distributions()->delete();

            // Simpan unit sebar jika scope adalah 'unit'
            if ($scope === 'unit' && !empty($data['unit_ids']) && is_array($data['unit_ids'])) {
                foreach ($data['unit_ids'] as $unitId) {
                    \App\Models\DocumentDistribution::create([
                        'document_id' => $document->id,
                        'unit_id'     => $unitId,
                    ]);
                }
            }

            AuditLog::catat('publikasi', "Dokumen dipublikasikan [{$scope}]: {$document->nomor_surat}", $document);

            $document->pengusul?->notify(new \App\Notifications\DokumenNotification(
                $document,
                'dipublikasikan',
                'Dokumen Resmi Dipublikasikan',
                "Dokumen Nomor '{$document->nomor_surat}' telah dipublikasikan ke Portal internal RS.",
                route('arsip.show', $document)
            ));

            return $document->fresh();
        });
    }

    /**
     * Tarik dokumen dari publikasi (unpublish).
     * Dokumen kembali ke status 'ditarik' dan tidak lagi dapat diakses publik.
     */
    public function unpublish(Document $document, string $alasan, ?int $penggantiDocumentId = null): Document
    {
        abort_unless(
            $document->status === Document::STATUS_DIPUBLIKASIKAN,
            403,
            'Hanya dokumen yang sedang dipublikasikan yang dapat ditarik.'
        );

        return DB::transaction(function () use ($document, $alasan, $penggantiDocumentId) {
            $document->update([
                'status'                => Document::STATUS_DITARIK,
                'ditarik_at'            => now(),
                'alasan_penarikan'      => $alasan,
                'pengganti_document_id' => $penggantiDocumentId,
            ]);

            $keterangan = "Dokumen ditarik dari publikasi: {$document->nomor_surat}. Alasan: {$alasan}";
            if ($penggantiDocumentId) {
                $pengganti = Document::find($penggantiDocumentId);
                $keterangan .= ". Digantikan oleh: {$pengganti?->nomor_surat}";
            }

            AuditLog::catat('unpublish', $keterangan, $document);

            return $document->fresh();
        });
    }

    /**
     * Publikasikan ulang dokumen yang sebelumnya ditarik.
     * Mengembalikan status ke 'dipublikasikan' dan menghapus catatan penarikan.
     */
    public function republish(Document $document, array $data = []): Document
    {
        abort_unless(
            $document->status === Document::STATUS_DITARIK,
            403,
            'Hanya dokumen yang berstatus ditarik yang dapat dipublikasikan ulang.'
        );

        return DB::transaction(function () use ($document, $data) {
            $scope = $data['visibility_scope'] ?? $document->visibility_scope ?? 'internal';

            $document->update([
                'status'                => Document::STATUS_DIPUBLIKASIKAN,
                'dipublikasikan_at'     => now(),
                'visibility_scope'      => $scope,
                'ditarik_at'            => null,
                'alasan_penarikan'      => null,
                'pengganti_document_id' => null,
            ]);

            // Hapus distribusi lama dan simpan baru jika scope = 'unit'
            $document->distributions()->delete();
            if ($scope === 'unit' && !empty($data['unit_ids']) && is_array($data['unit_ids'])) {
                foreach ($data['unit_ids'] as $unitId) {
                    \App\Models\DocumentDistribution::create([
                        'document_id' => $document->id,
                        'unit_id'     => $unitId,
                    ]);
                }
            }

            AuditLog::catat('republish', "Dokumen dipublikasikan ulang [{$scope}]: {$document->nomor_surat}", $document);

            return $document->fresh();
        });
    }

    /**
     * Generate nomor surat dengan atomic counter.
     */
    private function generateNomorSurat(Document $document): string
    {
        $type  = $document->documentType;
        $unit  = $document->unit;
        $tahun = (int) now()->format('Y');

        $nomorUrut = NumberingSequence::getNextNomor($type, $tahun);

        return $type->generateNomor($unit, $nomorUrut, now());
    }

    /**
     * Pastikan file .docx fisik ada di storage. Jika tidak ada (karena seeder / file hilang), buatkan file default.
     */
    public function ensureDocxFileExists(Document $document, ?DocumentVersion $version = null): string
    {
        $version = $version ?? $document->currentVersion;
        $fileRelativePath = $version ? $version->file_path : "documents/{$document->id}/naskah_v1.docx";

        if ($fileRelativePath && Storage::disk('local')->exists($fileRelativePath)) {
            return Storage::disk('local')->path($fileRelativePath);
        }
        if ($fileRelativePath && file_exists(storage_path('app/' . $fileRelativePath))) {
            return storage_path('app/' . $fileRelativePath);
        }
        if ($fileRelativePath && file_exists(storage_path('app/private/' . $fileRelativePath))) {
            return storage_path('app/private/' . $fileRelativePath);
        }
        if ($fileRelativePath && file_exists($fileRelativePath)) {
            return $fileRelativePath;
        }

        // Auto-generate file .docx fisik jika tidak ditemukan di disk
        $targetPath = storage_path('app/private/' . ($fileRelativePath ?: "documents/{$document->id}/naskah_v1.docx"));
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $htmlContent = "<h1>" . htmlspecialchars($document->judul) . "</h1>"
            . "<p><b>Perihal:</b> " . htmlspecialchars($document->perihal ?? '-') . "</p>"
            . "<p><b>Jenis Naskah:</b> " . htmlspecialchars($document->documentType?->nama ?? 'Naskah Dinas') . "</p>"
            . "<hr/>"
            . "<p>" . htmlspecialchars($document->keterangan ?? 'Isi naskah dinas SIMPEL-RS.') . "</p>";

        $this->createDocxFileFromHtml($htmlContent, $targetPath);

        return $targetPath;
    }

    /**
     * Generator file .docx dari konten HTML sederhana.
     */
    public function createDocxFileFromHtml(string $html, string $outputPath): void
    {
        $cleanHtml = strip_tags($html, '<p><br><b><strong><i><em><u><h1><h2><h3><h4><h5><h6><ul><ol><li><table><tr><td><th><span><div><hr>');

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8"><body>' . $cleanHtml . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $bodyXml = '';
        $bodyNode = $dom->getElementsByTagName('body')->item(0);
        if ($bodyNode) {
            foreach ($bodyNode->childNodes as $node) {
                if ($node->nodeType === XML_TEXT_NODE) {
                    if (trim($node->nodeValue) !== '') {
                        $bodyXml .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($node->nodeValue) . '</w:t></w:r></w:p>';
                    }
                } elseif ($node->nodeType === XML_ELEMENT_NODE) {
                    $tag = strtolower($node->nodeName);
                    if (in_array($tag, ['h1', 'h2', 'h3', 'h4'])) {
                        $bodyXml .= '<w:p><w:pPr><w:pStyle w:val="Heading' . substr($tag, 1) . '"/></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">' . htmlspecialchars($node->textContent) . '</w:t></w:r></w:p>';
                    } elseif ($tag === 'hr') {
                        $bodyXml .= '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="auto"/></w:pBdr></w:pPr></w:p>';
                    } else {
                        $bodyXml .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($node->textContent) . '</w:t></w:r></w:p>';
                    }
                }
            }
        }

        if (trim($bodyXml) === '') {
            $bodyXml = '<w:p><w:r><w:t xml:space="preserve">DRAFT NASKAH DINAS SIMPEL-RS</w:t></w:r></w:p>';
        }

        $zip = new \ZipArchive();
        $zip->open($outputPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>');

        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
</Relationships>');

        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>' . $bodyXml . '
    <w:sectPr>
      <w:pgSz w:w="11906" w:h="16838"/>
      <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/>
    </w:sectPr>
  </w:body>
</w:document>');

        $zip->close();
    }

    /**
     * Penandatangan menolak dokumen dan mengembalikan ke verifikator tertinggi.
     */
    public function tolakTandaTangan(Document $document, string $alasanTolak): Document
    {
        return DB::transaction(function () use ($document, $alasanTolak) {
            $user = auth()->user();

            // Sebelumnya method ini tidak memvalidasi status dokumen maupun wewenang penandatangan,
            // sehingga user manapun yang login bisa menolak dokumen apapun di status apapun.
            abort_unless(
                $document->status === Document::STATUS_MENUNGGU_TTD,
                403,
                'Dokumen tidak dalam status menunggu tanda tangan, tidak dapat dikembalikan.'
            );
            $this->assertAuthorizedSigner($document, $user);

            $currentVersionId = $document->currentVersion?->id;

            $document->update([
                'status'             => Document::STATUS_DITOLAK_TTD,
                'ditolak_ttd_alasan' => $alasanTolak,
                'ditolak_ttd_at'     => now(),
                'ditolak_ttd_oleh'   => $user->id,
            ]);

            // Cari verifikasi yang levelnya tertinggi dan disetujui pada versi aktif
            $highestLevelQuery = DocumentVerification::where('document_id', $document->id)
                ->where('status', DocumentVerification::STATUS_DISETUJUI);
            if ($currentVersionId) {
                $highestLevelQuery->where('document_version_id', $currentVersionId);
            }
            $highestLevel = $highestLevelQuery->max('level');

            if ($highestLevel) {
                $verificationsToReset = DocumentVerification::where('document_id', $document->id)
                    ->where('level', $highestLevel)
                    ->where('status', DocumentVerification::STATUS_DISETUJUI);
                if ($currentVersionId) {
                    $verificationsToReset->where('document_version_id', $currentVersionId);
                }

                foreach ($verificationsToReset->get() as $verif) {
                    $verif->update([
                        'status'         => DocumentVerification::STATUS_MENUNGGU,
                        'direset_alasan' => "Dikembalikan penandatangan: {$alasanTolak}",
                        'direset_at'     => now(),
                        'direspon_at'    => null,
                    ]);

                    $verif->verifikator?->notify(new \App\Notifications\DokumenNotification(
                        $document,
                        'ditolak_penandatangan',
                        'Dokumen Dikembalikan Penandatangan',
                        "Dokumen '{$document->judul}' dikembalikan. Catatan: {$alasanTolak}",
                        route('verifikasi.show', $verif)
                    ));
                }
            }

            AuditLog::catat('tolak_ttd', "Dikembalikan penandatangan: {$alasanTolak}", $document);

            $document->pengusul?->notify(new \App\Notifications\DokumenNotification(
                $document,
                'ditolak_penandatangan',
                'Dokumen Dikembalikan Penandatangan',
                "Dokumen '{$document->judul}' dikembalikan ke tahap verifikasi dengan catatan: {$alasanTolak}",
                route('dokumen.show', $document)
            ));

            return $document->fresh();
        });
    }

    /**
     * Verifikator meneruskan dokumen ke level verifikasi sebelumnya (bawah).
     */
    public function turunkanKeVerifikatorBawah(DocumentVerification $verification, string $alasan): Document
    {
        return DB::transaction(function () use ($verification, $alasan) {
            $document = $verification->document;
            $currentVersionId = $document->currentVersion?->id ?? $verification->document_version_id;
            
            $verification->update([
                'status'         => DocumentVerification::STATUS_MENUNGGU,
                'direset_alasan' => "Diturunkan sendiri dengan alasan: {$alasan}",
                'direset_at'     => now(),
            ]);

            // Cari level sebelumnya pada versi yang sama
            $lowerLevelQuery = DocumentVerification::where('document_id', $document->id)
                ->where('level', '<', $verification->level)
                ->where('status', DocumentVerification::STATUS_DISETUJUI);
            if ($currentVersionId) {
                $lowerLevelQuery->where('document_version_id', $currentVersionId);
            }
            $lowerLevel = $lowerLevelQuery->max('level');

            if ($lowerLevel) {
                $lowerVerifications = DocumentVerification::where('document_id', $document->id)
                    ->where('level', $lowerLevel)
                    ->where('status', DocumentVerification::STATUS_DISETUJUI);
                if ($currentVersionId) {
                    $lowerVerifications->where('document_version_id', $currentVersionId);
                }

                foreach ($lowerVerifications->get() as $lowerVerif) {
                    $lowerVerif->update([
                        'status'         => DocumentVerification::STATUS_MENUNGGU,
                        'direset_alasan' => "Dikembalikan dari level atas: {$alasan}",
                        'direset_at'     => now(),
                        'direspon_at'    => null,
                    ]);

                    $lowerVerif->verifikator?->notify(new \App\Notifications\DokumenNotification(
                        $document,
                        'dikembalikan_verifikator',
                        'Dokumen Dikembalikan ke Tahap Sebelumnya',
                        "Dokumen '{$document->judul}' dikembalikan dari tahap selanjutnya. Catatan: {$alasan}",
                        route('verifikasi.show', $lowerVerif)
                    ));
                }
                
                $document->update(['current_step' => $lowerLevel]);
            } else {
                $document->update(['status' => Document::STATUS_REVISI]);
                $document->pengusul?->notify(new \App\Notifications\DokumenNotification(
                    $document,
                    'revisi',
                    'Dokumen Perlu Diperbaiki',
                    "Dokumen '{$document->judul}' dikembalikan ke pengusul. Catatan: {$alasan}",
                    route('dokumen.show', $document)
                ));
            }

            AuditLog::catat('turunkan_verifikasi', "Diturunkan ke level bawah: {$alasan}", $document);

            return $document->fresh();
        });
    }
}
