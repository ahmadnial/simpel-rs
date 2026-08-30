<?php

namespace App\Services;

use App\Contracts\EvidenceSigner;
use App\Contracts\ImmutableEvidenceStore;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentVerification;
use App\Models\DocumentVersion;
use App\Models\NumberingSequence;
use App\Models\SignatureEvidence;
use App\Models\EvidenceStorageCopy;
use App\Models\SigningCeremony;
use App\Models\SigningOutboxMessage;
use App\Models\User;
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
    public function __construct(
        private readonly DocumentPdfService $pdfService,
        private readonly SigningOtpService $signingOtpService,
        private readonly CanonicalJson $canonicalJson,
        private readonly EvidenceSigner $evidenceSigner,
        private readonly EvidenceBundleService $evidenceBundleService,
        private readonly EvidenceVerificationService $evidenceVerificationService,
        private readonly AuditChainWriter $auditChainWriter,
        private readonly AuditCheckpointService $auditCheckpointService,
        private readonly EvidenceStorageService $evidenceStorageService,
        private readonly ImmutableEvidenceStore $immutableEvidenceStore,
    ) {
    }

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
    public function simpanVersi(Document $document, UploadedFile $file, ?string $catatan = null, ?int $uploadedById = null): DocumentVersion
    {
        abort_unless(strtolower($file->getClientOriginalExtension()) === 'docx', 422, 'Dokumen utama wajib berformat DOCX.');

        $version = DB::transaction(function () use ($document, $file, $catatan, $uploadedById) {
            Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $versiTerbaru = (int) ($document->versions()->lockForUpdate()->max('versi') ?? 0) + 1;
            $path = $file->store("documents/{$document->id}", 'local');

            try {
                $document->versions()->update(['is_current' => false]);

                return DocumentVersion::create([
                    'document_id' => $document->id,
                    'versi'       => $versiTerbaru,
                    'file_path'   => $path,
                    'file_name'   => $file->getClientOriginalName(),
                    'file_size'   => $file->getSize(),
                    'uploaded_by' => $uploadedById ?? auth()->id() ?? $document->pengusul_id,
                    'catatan'     => $catatan,
                    'is_current'  => true,
                ]);
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }
        });

        // Nilai versi dihitung di dalam transaksi; gunakan hasil transaksi di sini
        // agar tidak mengakses variabel lokal yang berada di luar scope closure.
        AuditLog::catat('upload_versi', "Versi {$version->versi} diunggah untuk dokumen: {$document->judul}", $document);

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
            $this->assertWorkflowIsValid($template);

            $revisionTicket = $document->status === Document::STATUS_REVISI
                ? DocumentVerification::where('document_id', $document->id)
                    ->where('status', DocumentVerification::STATUS_REVISI)
                    ->latest('direspon_at')
                    ->first()
                : null;
            $targetStep = $revisionTicket?->workflowStep ?? $template->steps()->where('tipe', 'verifikasi')->first();
            abort_unless($targetStep, 422, 'Langkah verifikasi belum dikonfigurasi pada workflow ini.');

            // Batalkan antrian verifikasi lama yang masih berstatus menunggu (jika pengajuan ulang dari revisi)
            DocumentVerification::where('document_id', $document->id)
                ->where('status', DocumentVerification::STATUS_MENUNGGU)
                ->update(['status' => DocumentVerification::STATUS_DIBATALKAN]);

            // visibility_scope TETAP 'terbatas' selama proses verifikasi+TTE berjalan — orang di
            // luar rantai verifikasi (bukan pengusul/verifikator/penandatangan/admin) memang belum
            // perlu lihat dokumen yang belum final. isAccessibleBy() sudah otomatis meng-izinkan
            // pengusul & setiap verifikator/penandatangan yang ditugaskan terlepas dari scope ini.
            // Visibilitas baru dilebarkan secara sadar lewat publikasi() setelah dokumen sah.

            $this->createVerificationsForStep(
                $document,
                $currentVersion,
                $targetStep,
                $targetStep->urutan,
                $targetStep->urutan === $template->steps()->where('tipe', 'verifikasi')->first()?->urutan ? $verifikatorIds : []
            );

            $document->update([
                'status'              => Document::STATUS_DIAJUKAN,
                'workflow_template_id'=> $template->id,
                'current_step'        => $targetStep->urutan,
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

            $people = collect();

            if (!$manual) {
                if ($step->isParallelQuorum()) {
                    foreach ($step->verifierPool as $pool) {
                        if ($pool->tipe_pool === 'user' && $pool->user) {
                            $people->push($this->personEntry($pool->user));
                        } elseif ($pool->tipe_pool === 'role' && $pool->role_nama) {
                            \App\Models\User::role($pool->role_nama)->where('is_active', true)->get()
                                ->each(fn ($u) => $people->push($this->personEntry($u)));
                        }
                    }
                } elseif ($step->role_nama) {
                    \App\Models\User::role($step->role_nama)->where('is_active', true)->get()
                        ->each(fn ($u) => $people->push($this->personEntry($u)));
                }
            }

            $people = $people->unique(fn ($p) => $p['name'] . '|' . $p['sub'])->values();
            $min = $step->min_approval ?? 1;

            if ($manual) {
                $note = 'Dipilih pengusul saat pengajuan';
            } elseif ($people->isEmpty()) {
                $note = 'Belum ada pejabat ditugaskan — hubungi Admin';
            } elseif ($step->isParallelQuorum() && $min < $people->count()) {
                $note = "Min. {$min} dari {$people->count()} orang menyetujui";
            } elseif ($people->count() > 1) {
                $note = 'Salah satu dari ' . $people->count() . ' orang';
            } else {
                $note = null;
            }

            // Kalau semua orang di tahap ini kebetulan berbagi jabatan/unit yang sama, tampilkan
            // sekali saja di label tahap — supaya tidak berulang di tiap nama (contoh nyata: 4
            // Asesor Internal jadi "Verifikator 1 · Asesor Internal" + daftar 4 nama polos,
            // bukan "Nama (Asesor Internal)" diulang 4 kali yang bikin baris jadi sangat panjang).
            $subs = $people->pluck('sub')->filter()->unique();
            $commonSub = $subs->count() === 1 ? $subs->first() : null;

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
                'manual'     => $manual,
                'note'       => $note,
                'commonSub'  => $commonSub,
                'people'     => $people->map(fn ($p) => [
                    'name' => $p['name'],
                    'sub'  => $commonSub ? null : $p['sub'],
                ])->values()->all(),
            ];
        })->values()->all();

        return ['configured' => !empty($stepsPreview), 'steps' => $stepsPreview];
    }

    private function personEntry(\App\Models\User $user): array
    {
        return ['name' => $user->name, 'sub' => $user->jabatan ?: $user->unit?->nama];
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
            $verif = DocumentVerification::updateOrCreate([
                'document_id'         => $document->id,
                'document_version_id' => $currentVersion->id,
                'workflow_step_id'    => $step->id,
                'verifikator_id'      => $v->id,
                'level'               => $level,
            ], [
                'status'              => DocumentVerification::STATUS_MENUNGGU,
                'batas_waktu'         => $this->addBusinessDays($step->sla_hari_kerja ?? 2),
                'catatan'             => null,
                'direspon_at'         => null,
                'direset_alasan'      => null,
                'direset_at'          => null,
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
            [$verification, $document] = $this->lockAndValidateVerificationAction($verification);
            $currentVersionId = $document->currentVersion->id;

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
                    'ditolak_ttd_alasan' => null,
                    'ditolak_ttd_at' => null,
                    'ditolak_ttd_oleh' => null,
                ]);
                AuditLog::catat('lolos_verifikasi', "Dokumen lolos semua verifikasi, menunggu pengesahan internal", $document);

                // Notifikasi Penandatangan: filter berdasarkan role spesifik jika terdefinisi
                $targetPenandatangans = collect();
                if ($penandatanganStep && $penandatanganStep->role_nama) {
                    $targetPenandatangans = \App\Models\User::role($penandatanganStep->role_nama)->where('is_active', true)->get();
                }
                
                if ($targetPenandatangans->isEmpty()) {
                    $targetPenandatangans = \App\Models\User::permission('dokumen.tanda_tangan')->where('is_active', true)->get();
                }

                // Pengganti Plt/Plh juga harus menerima notifikasi. Sebelumnya hanya
                // pejabat pemilik role yang diberi notifikasi, sehingga akun delegasi
                // dapat melihat antrian tetapi tidak pernah mendapat pemberitahuan.
                $delegatedPenandatangans = \App\Models\User::where('is_active', true)
                    ->get()
                    ->filter(function ($candidate) use ($penandatanganStep) {
                        $delegation = $candidate->activeDelegation();
                        return $delegation
                            && $delegation->pejabat
                            && (!$penandatanganStep?->role_nama
                                || $delegation->pejabat->hasRole($penandatanganStep->role_nama));
                    });
                $targetPenandatangans = $targetPenandatangans
                    ->merge($delegatedPenandatangans)
                    ->unique('id');

                foreach ($targetPenandatangans as $p) {
                    $p->notify(new \App\Notifications\DokumenNotification(
                        $document,
                        'menunggu_ttd',
                        'Dokumen Menunggu Pengesahan',
                        "Dokumen '{$document->judul}' telah disetujui penuh & siap ditandatangani.",
                        route('ttd.index')
                    ));
                }

                // Notifikasi Pengusul
                $document->pengusul?->notify(new \App\Notifications\DokumenNotification(
                    $document,
                    'menunggu_ttd',
                    'Verifikasi Dokumen Selesai',
                    "Dokumen '{$document->judul}' telah lolos verifikasi dan sedang dalam antrian pengesahan elektronik internal.",
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
            [$verification, $document] = $this->lockAndValidateVerificationAction($verification);

            $verification->update([
                'status'      => DocumentVerification::STATUS_REVISI,
                'catatan'     => $catatan,
                'direspon_at' => now(),
            ]);

            // Permintaan revisi adalah keputusan final untuk satu siklus dokumen.
            // Batalkan seluruh tiket aktif pada versi tersebut, bukan hanya tiket
            // dengan workflow_step yang sama. Ini menutup celah ketika konfigurasi
            // memiliki beberapa tiket/verifikator pada level berbeda atau halaman
            // verifikator lain masih terbuka.
            DocumentVerification::where('document_id', $document->id)
                ->where('document_version_id', $verification->document_version_id)
                ->whereKeyNot($verification->id)
                ->where('status', DocumentVerification::STATUS_MENUNGGU)
                ->update([
                    'status' => DocumentVerification::STATUS_DIBATALKAN,
                    'direset_alasan' => 'Siklus dihentikan karena ada permintaan revisi.',
                    'direset_at' => now(),
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
    public function tandaTangani(Document $document, string $otpInput, ?string $sessionId = null, int $reauthenticationAgeSeconds = 0): Document
    {
        $user = auth()->user();
        $sessionId ??= session()->getId();
        $document = $document->fresh(['currentVersion', 'workflowTemplate']);
        $this->assertCanSign($document, $user);
        $otpContext = $this->prepareOtpContext($document, $user, $sessionId, $reauthenticationAgeSeconds);
        $otpReceipt = $this->signingOtpService->verifyAndConsume($user, $document, $otpInput, $otpContext);

        $ceremony = SigningCeremony::where('uuid', $otpContext['signing_ceremony_id'])->firstOrFail();

        return $this->finalizeConsumedCeremony($ceremony, $otpReceipt, $user);
    }

    /**
     * Membentuk konteks server-side untuk challenge OTP v2 dari byte PDF kandidat.
     * Canonical manifest penuh diperkenalkan pada Paket C; draft ini sengaja minimal
     * namun deterministik dan sudah mengikat snapshot utama transaksi.
     *
     * @return array{document_version_id:int,pdf_hash:string,manifest_draft_hash:string,session_id:string,action:string,reauthentication_age_seconds:int,signing_ceremony_id:string}
     */
    public function prepareOtpContext(Document $document, User $user, string $sessionId, int $reauthenticationAgeSeconds = 0): array
    {
        abort_if($sessionId === '', 422, 'Sesi signing tidak tersedia.');
        $document = $document->fresh(['currentVersion', 'workflowTemplate', 'unit']);
        $this->assertCanSign($document, $user);
        $version = $document->currentVersion;
        abort_unless($version, 422, 'Versi aktif dokumen tidak ditemukan.');

        $sessionHash = hash('sha256', $sessionId);
        $activeKey = hash('sha256', implode('|', [$document->id, $version->id, $user->id, config('tte.otp.action')]));
        $idempotencyKey = hash('sha256', $activeKey.'|'.$sessionHash);
        $ceremony = DB::transaction(function () use ($document, $version, $user, $sessionHash, $activeKey, $idempotencyKey, $reauthenticationAgeSeconds) {
            $lockedDocument = Document::with(['currentVersion', 'documentType', 'unit'])->lockForUpdate()->findOrFail($document->id);
            abort_unless($lockedDocument->status === Document::STATUS_MENUNGGU_TTD, 403, 'Dokumen tidak dalam status menunggu tanda tangan.');
            abort_unless((int) $lockedDocument->currentVersion?->id === (int) $version->id, 409, 'Versi dokumen berubah saat ceremony disiapkan.');

            $existing = SigningCeremony::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing
                && in_array($existing->state, [SigningCeremony::STATE_PREPARING, SigningCeremony::STATE_AWAITING_USER_SIGNATURE], true)
                && $existing->expires_at->isFuture()) {
                return $existing;
            }

            abort_if($existing && $existing->state === SigningCeremony::STATE_USER_SIGNED, 409, 'Ceremony sebelumnya sedang menunggu finalisasi.');
            abort_if($existing && $existing->state === SigningCeremony::STATE_SEALED, 409, 'Ceremony sebelumnya sudah diselesaikan.');

            SigningCeremony::where('active_key', $activeKey)->lockForUpdate()->get()->each->update([
                'state' => SigningCeremony::STATE_FAILED,
                'active_key' => null,
                'failed_at' => now(),
                'failure_reason' => 'session_lineage_changed',
            ]);

            // idempotency_key bersifat unik untuk satu lineage sesi. Ceremony yang
            // gagal atau kedaluwarsa harus dihidupkan kembali, bukan di-insert lagi:
            // SQL Server akan (dengan benar) menolak insert kedua untuk key yang sama.
            if ($existing) {
                $existing->update([
                    'document_id' => $lockedDocument->id,
                    'document_version_id' => $version->id,
                    'intended_actor_id' => $user->id,
                    'intended_role' => $user->jabatan ?? $user->getRoleNames()->first() ?? 'penandatangan',
                    'delegation_id' => $user->activeDelegation()?->id,
                    'otp_challenge_id' => null,
                    'session_id_hash' => $sessionHash,
                    'nonce_hash' => hash('sha256', random_bytes(32)),
                    'manifest_draft_hash' => null,
                    'candidate_pdf_hash' => null,
                    'candidate_pdf_size' => null,
                    'candidate_pdf_path' => null,
                    'reserved_number' => $this->generateNomorSurat($lockedDocument),
                    'qr_token' => (string) Str::uuid(),
                    'state' => SigningCeremony::STATE_PREPARING,
                    'active_key' => $activeKey,
                    'reauthenticated_at' => now()->subSeconds($reauthenticationAgeSeconds),
                    'authorization_result' => true,
                    'expires_at' => now()->addMinutes(15),
                    'prepared_at' => null,
                    'consumed_at' => null,
                    'failed_at' => null,
                    'sealed_at' => null,
                    'failure_reason' => null,
                ]);

                return $existing->fresh();
            }

            return SigningCeremony::create([
                'uuid' => (string) Str::uuid(),
                'evidence_uuid' => (string) Str::uuid(),
                'document_id' => $lockedDocument->id,
                'document_version_id' => $version->id,
                'intended_actor_id' => $user->id,
                'intended_role' => $user->jabatan ?? $user->getRoleNames()->first() ?? 'penandatangan',
                'delegation_id' => $user->activeDelegation()?->id,
                'session_id_hash' => $sessionHash,
                'nonce_hash' => hash('sha256', random_bytes(32)),
                'reserved_number' => $this->generateNomorSurat($lockedDocument),
                'qr_token' => (string) Str::uuid(),
                'state' => SigningCeremony::STATE_PREPARING,
                'active_key' => $activeKey,
                'idempotency_key' => $idempotencyKey,
                'reauthenticated_at' => now()->subSeconds($reauthenticationAgeSeconds),
                'authorization_result' => true,
                'expires_at' => now()->addMinutes(15),
            ]);
        }, 3);

        if ($ceremony->state === SigningCeremony::STATE_PREPARING) {
            try {
                $ceremony = $this->renderAndLockCandidate($ceremony, $document, $version, $user);
            } catch (\Throwable $exception) {
                $ceremony->update([
                    'state' => SigningCeremony::STATE_FAILED,
                    'active_key' => null,
                    'failed_at' => now(),
                    'failure_reason' => 'candidate_render_failed',
                ]);
                throw $exception;
            }
        }

        return [
            'document_version_id' => (int) $version->id,
            'pdf_hash' => $ceremony->candidate_pdf_hash,
            'manifest_draft_hash' => $ceremony->manifest_draft_hash,
            'session_id' => $sessionId,
            'action' => (string) config('tte.otp.action'),
            'reauthentication_age_seconds' => $reauthenticationAgeSeconds,
            'signing_ceremony_id' => $ceremony->uuid,
        ];
    }

    private function renderAndLockCandidate(SigningCeremony $ceremony, Document $document, DocumentVersion $version, User $user): SigningCeremony
    {
        $document->loadMissing(['documentType', 'unit', 'pengusul', 'workflowTemplate', 'currentVersion']);
        $sourcePath = $this->ensureDocxFileExists($document, $version);
        $candidateDocument = $document->replicate();
        $candidateDocument->setAttribute('id', $document->id);
        $candidateDocument->exists = true;
        $candidateDocument->nomor_surat = $ceremony->reserved_number;
        $candidateDocument->tanggal_surat = $ceremony->created_at->toDateString();
        $candidateDocument->status = Document::STATUS_DITANDATANGANI;
        $candidateDocument->ditandatangani_at = $ceremony->created_at;
        foreach (['documentType', 'unit', 'pengusul', 'workflowTemplate', 'currentVersion'] as $relation) {
            $candidateDocument->setRelation($relation, $document->getRelation($relation));
        }

        $delegation = $user->activeDelegation();
        $visualSignature = new DocumentSignature([
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'penandatangan_id' => $user->id,
            'delegasi_id' => $delegation?->pejabat_id,
            'metode_tte' => 'internal_otp',
            'hash_dokumen' => str_repeat('0', 64),
            'qr_token' => $ceremony->qr_token,
            'ditandatangani_at' => $ceremony->created_at,
            'metadata_tte' => $this->signerMetadata($user, $delegation, $ceremony->created_at),
        ]);
        $visualSignature->setRelation('penandatangan', $user);
        $candidateDocument->setRelation('signature', $visualSignature);

        $processedDocx = (new DocxParserService)->processDocxTemplate($sourcePath, $candidateDocument);
        $renderedPdf = $this->pdfService->render($processedDocx, $candidateDocument, $version);
        abort_unless(is_file($renderedPdf) && filesize($renderedPdf) > 0, 500, 'PDF final kandidat gagal dibuat.');

        $candidatePath = "signing-candidates/{$ceremony->uuid}.pdf";
        Storage::disk('local')->put($candidatePath, file_get_contents($renderedPdf));
        $storedPath = Storage::disk('local')->path($candidatePath);
        abort_unless(is_file($storedPath) && filesize($storedPath) > 0, 500, 'PDF final kandidat gagal disimpan.');
        $pdfHash = hash_file('sha256', $storedPath);
        $pdfSize = filesize($storedPath);

        $draft = $this->manifestBase($ceremony, $document, $version, $user, $pdfHash, $pdfSize);
        $draft['manifest_stage'] = 'otp_challenge_draft';
        $draftBytes = $this->canonicalJson->encode($draft);

        return DB::transaction(function () use ($ceremony, $candidatePath, $pdfHash, $pdfSize, $draftBytes) {
            $locked = SigningCeremony::lockForUpdate()->findOrFail($ceremony->id);
            if ($locked->state === SigningCeremony::STATE_AWAITING_USER_SIGNATURE) {
                return $locked;
            }
            abort_unless($locked->state === SigningCeremony::STATE_PREPARING, 409, 'Ceremony tidak lagi dapat menerima PDF kandidat.');
            $locked->update([
                'candidate_pdf_path' => $candidatePath,
                'candidate_pdf_hash' => $pdfHash,
                'candidate_pdf_size' => $pdfSize,
                'manifest_draft_hash' => hash('sha256', $draftBytes),
                'state' => SigningCeremony::STATE_AWAITING_USER_SIGNATURE,
                'prepared_at' => now(),
            ]);

            return $locked->fresh();
        }, 3);
    }

    private function finalizeConsumedCeremony(SigningCeremony $ceremony, \App\Models\SignatureOtpChallenge $receipt, User $user): Document
    {
        $transition = DB::transaction(function () use ($ceremony, $receipt, $user) {
            $locked = SigningCeremony::lockForUpdate()->findOrFail($ceremony->id);
            if ($locked->state === SigningCeremony::STATE_SEALED) {
                return $locked;
            }
            abort_unless($locked->state === SigningCeremony::STATE_AWAITING_USER_SIGNATURE, 409, 'Ceremony tidak berada pada state yang dapat difinalisasi.');
            abort_unless($locked->intended_actor_id === (int) $user->id, 403, 'Aktor ceremony tidak cocok.');
            abort_unless($receipt->state === \App\Models\SignatureOtpChallenge::STATE_CONSUMED, 409, 'Receipt OTP belum dikonsumsi.');
            abort_unless($receipt->signing_ceremony_id === $locked->uuid, 409, 'Receipt OTP tidak terikat pada ceremony ini.');
            $locked->update([
                'state' => SigningCeremony::STATE_USER_SIGNED,
                'active_key' => null,
                'otp_challenge_id' => $receipt->id,
                'consumed_at' => $receipt->consumed_at,
            ]);
            SigningOutboxMessage::firstOrCreate(
                ['idempotency_key' => hash('sha256', 'finalize|'.$locked->uuid)],
                [
                    'uuid' => (string) Str::uuid(),
                    'signing_ceremony_id' => $locked->id,
                    'type' => 'finalize_signature_evidence',
                    'payload' => ['ceremony_uuid' => $locked->uuid, 'otp_challenge_uuid' => $receipt->uuid],
                    'state' => 'pending',
                    'available_at' => now(),
                ]
            );

            return $locked->fresh();
        }, 3);

        if ($transition->state === SigningCeremony::STATE_SEALED) {
            return Document::findOrFail($transition->document_id);
        }

        return $this->completeFinalization($transition, $receipt, $user);
    }

    public function resumeFinalization(SigningCeremony $ceremony): Document
    {
        $ceremony = $ceremony->fresh(['otpChallenge', 'intendedActor']);
        if ($ceremony->state === SigningCeremony::STATE_SEALED) {
            return Document::findOrFail($ceremony->document_id);
        }
        abort_unless($ceremony->state === SigningCeremony::STATE_USER_SIGNED, 409, 'Ceremony tidak menunggu rekonsiliasi finalisasi.');
        abort_unless($ceremony->otpChallenge && $ceremony->intendedActor, 409, 'Receipt atau aktor ceremony tidak ditemukan.');

        return $this->completeFinalization($ceremony, $ceremony->otpChallenge, $ceremony->intendedActor);
    }

    private function completeFinalization(SigningCeremony $ceremony, \App\Models\SignatureOtpChallenge $receipt, User $user): Document
    {
        $ceremony->loadMissing('delegation.pejabat');
        $document = Document::with(['currentVersion', 'documentType', 'unit', 'pengusul', 'workflowTemplate.steps', 'verifications.verifikator'])->findOrFail($ceremony->document_id);
        $version = $document->currentVersion;
        abort_unless($version && $version->id === $ceremony->document_version_id, 409, 'Versi aktif berubah sebelum finalisasi.');
        $this->assertCanSign($document, $user);
        if ($ceremony->delegation_id) {
            abort_unless(
                $ceremony->delegation
                && $ceremony->delegation->delegasi_id === (int) $user->id
                && $ceremony->delegation->isCurrentlyActive(),
                409,
                'Delegasi ceremony tidak lagi berlaku.'
            );
        }
        abort_unless(Storage::disk('local')->exists($ceremony->candidate_pdf_path), 500, 'PDF kandidat ceremony hilang.');
        $candidatePath = Storage::disk('local')->path($ceremony->candidate_pdf_path);
        abort_unless(hash_equals($ceremony->candidate_pdf_hash, hash_file('sha256', $candidatePath)), 500, 'Hash PDF kandidat berubah.');

        $official = $this->pdfService->persistOfficial($document, $version, $candidatePath, $ceremony->qr_token);
        abort_unless(hash_equals($ceremony->candidate_pdf_hash, $official['hash']) && $ceremony->candidate_pdf_size === (int) $official['size'], 500, 'Verifikasi write PDF resmi gagal.');

        // Waktu seal berasal dari consume receipt agar retry menghasilkan bytes yang identik.
        $sealedAt = $receipt->consumed_at->utc();
        $auditEvent = $this->auditChainWriter->append(
            'document_signing_user_confirmed',
            [
                'ceremony_uuid' => $ceremony->uuid,
                'document_uuid' => $document->uuid,
                'evidence_uuid' => $ceremony->evidence_uuid,
                'otp_receipt_uuid' => $receipt->uuid,
                'pdf_hash' => $official['hash'],
            ],
            $user->id,
            Document::class,
            (string) $document->id,
            idempotencyKey: "signing-user-confirmed|{$ceremony->uuid}",
        );
        $checkpoint = $this->auditCheckpointService->create();
        $activeKey = $this->evidenceSigner->activeKey();
        $manifest = $this->manifestBase($ceremony, $document, $version, $user, $official['hash'], (int) $official['size']);
        $manifest['manifest_stage'] = 'final';
        $manifest['institution_seal'] = [
            'algorithm' => $activeKey->algorithm,
            'key_fingerprint' => $activeKey->fingerprint,
            'key_id' => $activeKey->keyId,
            'meaning' => 'SIMPEL-RS institutional integrity seal; not a personal PSrE certificate',
        ];
        $manifest['otp_receipt'] = $this->otpReceiptSnapshot($receipt, $sealedAt);
        $manifest['audit_receipt'] = [
            'checkpoint_hash' => $checkpoint->checkpoint_hash,
            'checkpoint_id' => $checkpoint->uuid,
            'event_hash' => $auditEvent->event_hash,
            'event_sequence' => $auditEvent->sequence,
            'stream_id' => $auditEvent->stream_id,
        ];
        $storageDescriptor = $this->immutableEvidenceStore->descriptor();
        $manifest['immutable_storage_plan'] = [
            'bucket_logical_id' => $storageDescriptor['bucket'],
            'object_prefix' => "evidence/{$ceremony->evidence_uuid}/",
            'provider' => $storageDescriptor['provider'],
            'retention_mode' => $storageDescriptor['retention_mode'],
        ];
        $manifest['sealed_at_utc'] = $this->utc($sealedAt);
        $canonicalManifest = $this->canonicalJson->encode($manifest);
        $manifestHash = hash('sha256', $canonicalManifest);
        $institutionSignature = $this->evidenceSigner->sign($canonicalManifest);
        abort_unless(
            hash_equals($activeKey->keyId, $institutionSignature->key->keyId)
            && hash_equals($activeKey->fingerprint, $institutionSignature->key->fingerprint),
            500,
            'Signing key berubah selama operasi seal.'
        );
        $bundle = $this->evidenceBundleService->build(
            $ceremony->evidence_uuid,
            Storage::disk('local')->path($official['path']),
            $canonicalManifest,
            $manifest['otp_receipt'],
            $institutionSignature,
        );
        $bundleVerification = $this->evidenceVerificationService->verifyBundle(
            Storage::disk('local')->path($bundle['path']),
            $institutionSignature->key->toArray(),
        );
        abort_unless($bundleVerification['valid'], 500, 'Evidence bundle gagal verifikasi mandiri setelah dibuat.');
        $signatureJson = $this->canonicalJson->encode([
            'algorithm' => $institutionSignature->key->algorithm,
            'key_fingerprint' => $institutionSignature->key->fingerprint,
            'key_id' => $institutionSignature->key->keyId,
            'manifest_hash' => $manifestHash,
            'signature' => $institutionSignature->signature,
        ]);
        $storageReceipts = $this->evidenceStorageService->storeAndVerify($ceremony->evidence_uuid, [
            'document.pdf' => file_get_contents(Storage::disk('local')->path($official['path'])),
            'evidence-manifest.json' => $canonicalManifest,
            'institution-signature.json' => $signatureJson,
            'otp-receipt.json' => $this->canonicalJson->encode($manifest['otp_receipt']),
            'public-key.json' => $this->canonicalJson->encode($institutionSignature->key->toArray()),
            'evidence-bundle.zip' => file_get_contents(Storage::disk('local')->path($bundle['path'])),
        ]);
        $documentSnapshot = $manifest['document_snapshot'];
        $signerSnapshot = $manifest['signer_snapshot'];
        $workflowSnapshot = $manifest['workflow_snapshot'];
        $delegationSnapshot = $manifest['delegation_snapshot'];
        $otpSnapshot = $manifest['otp_receipt'];

        $sealedDocument = DB::transaction(function () use ($ceremony, $receipt, $user, $document, $version, $official, $sealedAt, $canonicalManifest, $manifestHash, $documentSnapshot, $signerSnapshot, $workflowSnapshot, $delegationSnapshot, $otpSnapshot, $institutionSignature, $bundle, $storageReceipts) {
            $lockedDocument = Document::lockForUpdate()->findOrFail($document->id);
            $lockedCeremony = SigningCeremony::lockForUpdate()->findOrFail($ceremony->id);
            if ($existing = SignatureEvidence::where('signing_ceremony_id', $lockedCeremony->id)->first()) {
                return $lockedDocument;
            }
            abort_unless($lockedDocument->status === Document::STATUS_MENUNGGU_TTD, 409, 'Status dokumen berubah sebelum finalisasi.');
            abort_unless($lockedCeremony->state === SigningCeremony::STATE_USER_SIGNED, 409, 'State ceremony berubah sebelum finalisasi.');
            abort_if(DocumentSignature::where('document_id', $lockedDocument->id)->exists(), 409, 'Dokumen ini sudah memiliki pengesahan.');

            $evidence = SignatureEvidence::create([
                'uuid' => $lockedCeremony->evidence_uuid,
                'schema_version' => '2.0',
                'assurance_profile' => config('tte.profile'),
                'document_id' => $lockedDocument->id,
                'document_version_id' => $version->id,
                'signing_ceremony_id' => $lockedCeremony->id,
                'otp_challenge_id' => $receipt->id,
                'pdf_hash' => $official['hash'],
                'pdf_size' => $official['size'],
                'pdf_path' => $official['path'],
                'canonical_manifest' => $canonicalManifest,
                'manifest_hash' => $manifestHash,
                'document_snapshot' => $documentSnapshot,
                'signer_snapshot' => $signerSnapshot,
                'workflow_snapshot' => $workflowSnapshot,
                'delegation_snapshot' => $delegationSnapshot,
                'otp_receipt' => $otpSnapshot,
                'signature_algorithm' => $institutionSignature->key->algorithm,
                'signing_key_id' => $institutionSignature->key->keyId,
                'signing_key_fingerprint' => $institutionSignature->key->fingerprint,
                'institution_signature' => $institutionSignature->signature,
                'bundle_path' => $bundle['path'],
                'bundle_hash' => $bundle['hash'],
                'bundle_size' => $bundle['size'],
                'state' => 'immutable_verified',
                'sealed_at' => $sealedAt,
            ]);
            foreach ($storageReceipts as $artifactType => $storageReceipt) {
                EvidenceStorageCopy::create([
                    'signature_evidence_id' => $evidence->id,
                    'evidence_uuid' => $evidence->uuid,
                    'artifact_type' => $artifactType,
                    'storage_provider' => $storageReceipt->provider,
                    'bucket_logical_id' => $storageReceipt->bucket,
                    'object_key' => $storageReceipt->objectKey,
                    'object_version_id' => $storageReceipt->versionId,
                    'checksum' => $storageReceipt->checksum,
                    'size' => $storageReceipt->size,
                    'retention_mode' => $storageReceipt->retentionMode,
                    'retention_until' => $storageReceipt->retentionUntil,
                    'verified_at' => $storageReceipt->verifiedAt,
                    'state' => 'verified_after_write',
                ]);
            }

            $delegation = $lockedCeremony->delegation_id ? \App\Models\Delegation::find($lockedCeremony->delegation_id) : null;
            DocumentSignature::create([
                'document_id' => $lockedDocument->id,
                'document_version_id' => $version->id,
                'penandatangan_id' => $user->id,
                'delegasi_id' => $delegation?->pejabat_id,
                'metode_tte' => 'internal_otp',
                'hash_dokumen' => $official['hash'],
                'qr_token' => $lockedCeremony->qr_token,
                'file_signed_path' => $official['path'],
                'metadata_tte' => $this->signerMetadata($user, $delegation, $sealedAt),
                'ditandatangani_at' => $receipt->consumed_at,
                'signature_evidence_id' => $evidence->id,
                'otp_challenge_id' => $receipt->id,
                'assurance_profile' => config('tte.profile'),
            ]);
            $version->update(['file_pdf_path' => $official['path']]);
            $lockedDocument->update([
                'status' => Document::STATUS_DITANDATANGANI,
                'nomor_surat' => $lockedCeremony->reserved_number,
                'tanggal_surat' => $sealedAt->toDateString(),
                'hash_final' => $official['hash'],
                'ditandatangani_at' => $receipt->consumed_at,
            ]);
            $lockedCeremony->update([
                'state' => SigningCeremony::STATE_SEALED,
                'sealed_at' => $sealedAt,
                'failure_reason' => null,
            ]);
            SigningOutboxMessage::where('signing_ceremony_id', $lockedCeremony->id)
                ->where('type', 'finalize_signature_evidence')
                ->update(['state' => 'processed', 'processed_at' => $sealedAt]);
            $this->signingOtpService->markSealed($receipt);
            AuditLog::catat('tanda_tangan', "Dokumen ditandatangani, nomor: {$lockedCeremony->reserved_number}", $lockedDocument, [], ['nomor_surat' => $lockedCeremony->reserved_number, 'evidence_uuid' => $evidence->uuid]);

            return $lockedDocument->fresh();
        }, 3);

        try {
            $sealedDocument->pengusul?->notify(new \App\Notifications\DokumenNotification(
                $sealedDocument,
                'ditandatangani',
                'Dokumen Berhasil Disahkan',
                "Dokumen '{$sealedDocument->judul}' telah disahkan secara elektronik di SIMPEL-RS dengan Nomor: {$sealedDocument->nomor_surat}",
                route('dokumen.show', $sealedDocument)
            ));
        } catch (\Throwable) {
            // Bukti yang sudah tersegel tidak boleh dibatalkan hanya karena notifikasi pasca-signing gagal.
        }

        return $sealedDocument;
    }

    private function manifestBase(SigningCeremony $ceremony, Document $document, DocumentVersion $version, User $user, string $pdfHash, int $pdfSize): array
    {
        $document->loadMissing(['documentType', 'unit', 'workflowTemplate.steps', 'verifications.verifikator']);
        $workflowSteps = $document->workflowTemplate?->steps->sortBy('urutan')->values()->map(fn ($step) => [
            'mode' => $step->mode_verifikasi,
            'name' => $step->nama_tahap,
            'order' => (int) $step->urutan,
            'role' => $step->role_nama,
            'type' => $step->tipe,
        ])->all() ?? [];
        $approvals = $document->verifications->sortBy(fn ($verification) => sprintf('%05d-%020d', $verification->level, $verification->id))->values()->map(fn ($verification) => [
            'actor_id' => (int) $verification->verifikator_id,
            'actor_name' => $verification->verifikator?->name,
            'decision' => $verification->status,
            'level' => (int) $verification->level,
            'time_utc' => $verification->updated_at ? $this->utc($verification->updated_at) : null,
        ])->all();
        $workflowSnapshot = [
            'approvals' => $approvals,
            'steps' => $workflowSteps,
            'template_id' => (int) $document->workflow_template_id,
        ];
        $workflowSnapshot['snapshot_hash'] = hash('sha256', $this->canonicalJson->encode($workflowSnapshot));
        $ceremony->loadMissing('delegation.pejabat');
        $delegation = $ceremony->delegation;

        return [
            'assurance_profile' => (string) config('tte.profile'),
            'ceremony_id' => $ceremony->uuid,
            'delegation_snapshot' => $delegation ? [
                'delegate_id' => (int) $delegation->delegasi_id,
                'from' => $delegation->berlaku_dari->toDateString(),
                'principal_id' => (int) $delegation->pejabat_id,
                'principal_name' => $delegation->pejabat?->name,
                'record_id' => (int) $delegation->id,
                'to' => $delegation->berlaku_sampai->toDateString(),
                'type' => $delegation->tipe,
            ] : null,
            'document_snapshot' => [
                'classification' => $document->documentType?->nama,
                'document_id' => (int) $document->id,
                'document_uuid' => $document->uuid,
                'effective_date' => $ceremony->created_at->toDateString(),
                'number' => $ceremony->reserved_number,
                'title' => $document->judul,
                'unit_id' => (int) $document->unit_id,
                'unit_name' => $document->unit?->nama,
                'version_id' => (int) $version->id,
                'version_number' => (int) $version->versi,
            ],
            'evidence_id' => $ceremony->evidence_uuid,
            'file' => ['mime_type' => 'application/pdf', 'sha256' => $pdfHash, 'size' => $pdfSize],
            'schema_version' => '2.0',
            'signer_snapshot' => [
                'email_destination_masked' => $this->maskEmail($user->email),
                'name' => $user->name,
                'nip' => $user->nip,
                'organization_role' => $user->jabatan,
                'signing_role' => $ceremony->intended_role,
                'unit_id' => $user->unit_id ? (int) $user->unit_id : null,
                'user_id' => (int) $user->id,
            ],
            'time_sources' => [
                'application_utc' => $this->utc($ceremony->created_at),
                'database_utc' => $this->databaseUtc(),
                'trust_level' => 'internal_only',
            ],
            'workflow_snapshot' => $workflowSnapshot,
        ];
    }

    private function otpReceiptSnapshot(\App\Models\SignatureOtpChallenge $receipt, $sealedAt): array
    {
        return [
            'action' => $receipt->action,
            'attempt_count' => (int) $receipt->attempt_count,
            'challenge_id' => $receipt->uuid,
            'consumed_at_utc' => $this->utc($receipt->consumed_at),
            'destination_hash' => $receipt->destination_hash,
            'document_id' => (int) $receipt->document_id,
            'document_version_id' => (int) $receipt->document_version_id,
            'expires_at_utc' => $this->utc($receipt->expires_at),
            'manifest_draft_hash' => $receipt->manifest_draft_hash,
            'masked_destination' => $receipt->masked_destination,
            'method' => 'otp_email_internal',
            'nonce_hash' => $receipt->nonce_hash,
            'pdf_hash' => $receipt->pdf_hash,
            'policy_version' => $receipt->policy_version,
            'requested_at_utc' => $this->utc($receipt->requested_at),
            'resend_generation' => (int) $receipt->resend_generation,
            'sealed_at_utc' => $this->utc($sealedAt),
            'sent_at_utc' => $this->utc($receipt->sent_at),
            'session_id_hash' => $receipt->session_id_hash,
            'user_id' => (int) $receipt->user_id,
            'verified_at_utc' => $this->utc($receipt->verified_at),
        ];
    }

    private function signerMetadata(User $user, $delegation, $signedAt): array
    {
        return [
            'actor_name' => $user->name,
            'actor_user_id' => $user->id,
            'signer_role' => $user->jabatan ?? $user->getRoleNames()->first() ?? 'Penandatangan',
            'principal_name' => $delegation?->pejabat?->name,
            'principal_user_id' => $delegation?->pejabat_id,
            'delegation_record_id' => $delegation?->id,
            'delegation_type' => $delegation?->tipe,
            'delegation_from' => $delegation?->berlaku_dari?->toDateString(),
            'delegation_until' => $delegation?->berlaku_sampai?->toDateString(),
            'signed_at' => $this->utc($signedAt),
        ];
    }

    private function utc($date): string
    {
        return $date->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', strtolower(trim($email)), 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }

    private function databaseUtc(): string
    {
        $row = DB::selectOne('SELECT CURRENT_TIMESTAMP AS database_utc');

        return \Illuminate\Support\Carbon::parse($row->database_utc)->utc()->format('Y-m-d\TH:i:s\Z');
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

        $isAuthorizedSigner = $document->workflowTemplate?->steps()
                ->where('tipe', 'penandatangan')
                ->whereIn('role_nama', $signerRoles)
                ->exists() ?? false;

        abort_unless($isAuthorizedSigner, 403, 'Anda bukan penandatangan yang sah untuk dokumen ini.');
    }

    public function assertCanSign(Document $document, \App\Models\User $user): void
    {
        abort_unless($document->status === Document::STATUS_MENUNGGU_TTD, 403, 'Dokumen tidak berada dalam antrian pengesahan.');
        $this->assertAuthorizedSigner($document, $user);
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

        // Format Nomor sudah divalidasi unik antar Jenis Naskah sejak Admin > Jenis Naskah (lihat
        // DocumentTypeController), jadi collision seharusnya tidak terjadi lagi. Loop-check ini
        // cuma jaring pengaman untuk data lama (nomor_surat yang pernah dimasukkan manual/di luar
        // alur ini) — supaya kalau tetap bentrok, pengguna dapat pesan jelas, bukan error SQL
        // mentah di tengah proses tanda tangan.
        for ($percobaan = 0; $percobaan < 5; $percobaan++) {
            $nomorUrut = NumberingSequence::getNextNomor($type, $tahun);
            $nomor = $type->generateNomor($unit, $nomorUrut, now());

            if (!Document::where('nomor_surat', $nomor)->exists()) {
                return $nomor;
            }
        }

        abort(422, "Gagal membuat nomor surat unik untuk jenis naskah '{$type->nama}' setelah beberapa percobaan — kemungkinan Format Nomor jenis naskah ini tumpang tindih dengan jenis naskah lain. Hubungi Admin untuk memeriksa Format Nomor di menu Admin &gt; Jenis Naskah.");
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
            $document = Document::with('currentVersion')->lockForUpdate()->findOrFail($document->id);

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

            abort_unless($highestLevel, 422, 'Tidak ada tahap verifikasi yang dapat diaktifkan kembali. Hubungi administrator workflow.');

            DocumentVerification::where('document_id', $document->id)
                ->where('document_version_id', $currentVersionId)
                ->where('status', DocumentVerification::STATUS_MENUNGGU)
                ->update([
                    'status' => DocumentVerification::STATUS_DIBATALKAN,
                    'direset_alasan' => 'Antrian ditutup karena dokumen dikembalikan oleh penandatangan.',
                    'direset_at' => now(),
                ]);

            $verificationsToReset = DocumentVerification::where('document_id', $document->id)
                    ->where('level', $highestLevel)
                    ->where('document_version_id', $currentVersionId);

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

            $document->update(['current_step' => $highestLevel]);

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
            [$verification, $document] = $this->lockAndValidateVerificationAction($verification);
            $currentVersionId = $document->currentVersion->id;

            // Cari level sebelumnya pada versi yang sama
            $lowerLevelQuery = DocumentVerification::where('document_id', $document->id)
                ->where('level', '<', $verification->level)
                ->where('status', DocumentVerification::STATUS_DISETUJUI);
            $lowerLevelQuery->where('document_version_id', $currentVersionId);
            $lowerLevel = $lowerLevelQuery->max('level');

            if ($lowerLevel) {
                $verification->update([
                    'status'         => DocumentVerification::STATUS_DIBATALKAN,
                    'direset_alasan' => "Ditangguhkan karena dikembalikan ke level {$lowerLevel}: {$alasan}",
                    'direset_at'     => now(),
                    'direspon_at'    => now(),
                ]);

                DocumentVerification::where('document_id', $document->id)
                    ->where('document_version_id', $currentVersionId)
                    ->where('level', $verification->level)
                    ->where('status', DocumentVerification::STATUS_MENUNGGU)
                    ->update([
                        'status' => DocumentVerification::STATUS_DIBATALKAN,
                        'direset_alasan' => "Tahap ditangguhkan karena dikembalikan ke level {$lowerLevel}.",
                        'direset_at' => now(),
                    ]);

                $lowerVerifications = DocumentVerification::where('document_id', $document->id)
                    ->where('level', $lowerLevel)
                    ->where('document_version_id', $currentVersionId);

                foreach ($lowerVerifications->get() as $lowerVerif) {
                    $lowerVerif->update([
                        'status'         => DocumentVerification::STATUS_MENUNGGU,
                        'direset_alasan' => "Dikembalikan dari level atas: {$alasan}",
                        'direset_at'     => now(),
                        'direspon_at'    => null,
                        'catatan'        => null,
                    ]);

                    $lowerVerif->verifikator?->notify(new \App\Notifications\DokumenNotification(
                        $document,
                        'dikembalikan_verifikator',
                        'Dokumen Dikembalikan ke Tahap Sebelumnya',
                        "Dokumen '{$document->judul}' dikembalikan dari tahap selanjutnya. Catatan: {$alasan}",
                        route('verifikasi.show', $lowerVerif)
                    ));
                }
                
                $document->update([
                    'status' => Document::STATUS_VERIFIKASI,
                    'current_step' => $lowerLevel,
                ]);
            } else {
                abort(422, 'Tahap ini tidak memiliki level verifikasi sebelumnya. Gunakan aksi Minta Revisi ke Pengusul.');
            }

            AuditLog::catat('turunkan_verifikasi', "Diturunkan ke level bawah: {$alasan}", $document);

            return $document->fresh();
        });
    }

    /**
     * Kunci dan validasi tiket sebelum transisi agar tiket lama/tidak aktif tidak dapat diputar ulang.
     */
    private function lockAndValidateVerificationAction(DocumentVerification $verification): array
    {
        $verification = DocumentVerification::with('workflowStep')
            ->lockForUpdate()
            ->findOrFail($verification->id);
        $document = Document::with('currentVersion')
            ->lockForUpdate()
            ->findOrFail($verification->document_id);
        $user = auth()->user();
        $delegation = $user->activeDelegation();
        $isAssignee = $verification->verifikator_id === $user->id
            || ($delegation && $delegation->pejabat_id === $verification->verifikator_id);

        abort_unless($isAssignee, 403, 'Anda bukan pemilik tiket verifikasi aktif ini.');
        abort_unless($verification->status === DocumentVerification::STATUS_MENUNGGU, 409, 'Tiket verifikasi ini sudah diproses atau dibatalkan.');
        abort_unless($document->currentVersion?->id === $verification->document_version_id, 409, 'Tiket berasal dari versi dokumen lama dan tidak dapat diproses.');
        abort_unless((int) $document->current_step === (int) $verification->level, 409, 'Tahap verifikasi ini sudah tidak aktif.');
        abort_unless(in_array($document->status, [Document::STATUS_DIAJUKAN, Document::STATUS_VERIFIKASI, Document::STATUS_DITOLAK_TTD], true), 409, 'Dokumen tidak sedang berada pada proses verifikasi aktif.');

        return [$verification, $document];
    }

    private function assertWorkflowIsValid(WorkflowTemplate $template): void
    {
        $steps = $template->steps()->with('verifierPool.user')->get()->values();
        abort_if($steps->isEmpty(), 422, 'Workflow belum memiliki tahapan.');
        abort_unless($steps->where('tipe', 'penandatangan')->count() === 1, 422, 'Workflow wajib memiliki tepat satu tahap penandatangan.');
        abort_unless($steps->where('tipe', 'verifikasi')->isNotEmpty(), 422, 'Workflow wajib memiliki minimal satu tahap verifikasi.');
        abort_unless($steps->last()->tipe === 'penandatangan', 422, 'Tahap penandatangan wajib menjadi tahap terakhir.');
        abort_unless($steps->pluck('urutan')->unique()->count() === $steps->count(), 422, 'Nomor urutan workflow tidak boleh duplikat.');

        $signer = $steps->last();
        abort_unless(
            $signer->role_nama && \App\Models\User::role($signer->role_nama)->where('is_active', true)->exists(),
            422,
            'Role penandatangan belum memiliki pengguna aktif.'
        );
    }

    private function addBusinessDays(int $days): \Illuminate\Support\Carbon
    {
        $deadline = now();
        $remaining = max(1, $days);
        while ($remaining > 0) {
            $deadline->addDay();
            if (!$deadline->isWeekend()) {
                $remaining--;
            }
        }

        return $deadline;
    }
}
