<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentVerification;
use App\Models\DocumentVersion;
use App\Models\NumberingSequence;
use App\Models\WorkflowStep;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

            $document = Document::create([
                'judul'               => $data['judul'],
                'document_type_id'    => $data['document_type_id'],
                'unit_id'             => $data['unit_id'] ?? $user->unit_id,
                'pengusul_id'         => $user->id,
                'workflow_template_id'=> $data['workflow_template_id'] ?? null,
                'perihal'             => $data['perihal'] ?? null,
                'keterangan'          => $data['keterangan'] ?? null,
                'is_rahasia'          => $data['is_rahasia'] ?? false,
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
    public function ajukanDokumen(Document $document, int $verifikatorId): Document
    {
        return DB::transaction(function () use ($document, $verifikatorId) {
            abort_unless(
                in_array($document->status, [Document::STATUS_DRAFT, Document::STATUS_REVISI]),
                403, 'Dokumen tidak dapat diajukan dari status saat ini.'
            );

            $currentVersion = $document->currentVersion;
            abort_unless($currentVersion, 422, 'Tidak ada file dokumen yang diunggah.');

            // Tentukan workflow template
            $template = $document->workflowTemplate
                ?? $document->documentType->workflowTemplates()->where('is_default', true)->first();

            $firstStep = $template?->steps()->orderBy('urutan')->first();

            // Buat record verifikasi
            DocumentVerification::create([
                'document_id'         => $document->id,
                'document_version_id' => $currentVersion->id,
                'workflow_step_id'    => $firstStep?->id,
                'verifikator_id'      => $verifikatorId,
                'level'               => 1,
                'status'              => DocumentVerification::STATUS_MENUNGGU,
                'batas_waktu'         => now()->addDays($firstStep?->sla_hari_kerja ?? 2),
            ]);

            $document->update([
                'status'              => Document::STATUS_DIAJUKAN,
                'workflow_template_id'=> $template?->id,
                'current_step'        => 1,
                'diajukan_at'         => now(),
            ]);

            AuditLog::catat('ajukan', "Dokumen diajukan ke verifikasi", $document);

            // Kirim notifikasi ke verifikator
            $verifikator = \App\Models\User::find($verifikatorId);
            $verifikator?->notify(new \App\Notifications\DokumenMenungguVerifikasi($document));

            return $document->fresh();
        });
    }

    /**
     * Verifikator menyetujui dokumen.
     */
    public function setujui(DocumentVerification $verification, ?string $catatan = null): Document
    {
        return DB::transaction(function () use ($verification, $catatan) {
            $document = $verification->document;

            $verification->update([
                'status'      => DocumentVerification::STATUS_DISETUJUI,
                'catatan'     => $catatan,
                'direspon_at' => now(),
            ]);

            AuditLog::catat('setujui', "Dokumen disetujui oleh " . auth()->user()->name, $document);

            // Cek apakah ada level verifikasi berikutnya
            $template = $document->workflowTemplate;
            $nextStep = $template?->steps()
                ->where('urutan', '>', $verification->level)
                ->orderBy('urutan')
                ->first();

            if ($nextStep) {
                // Masih ada step berikutnya
                $document->update([
                    'status'       => Document::STATUS_VERIFIKASI,
                    'current_step' => $nextStep->urutan,
                ]);
            } else {
                // Semua verifikasi selesai → Convert PDF → menunggu TTD
                $document->update(['status' => Document::STATUS_MENUNGGU_TTD]);
                AuditLog::catat('lolos_verifikasi', "Dokumen lolos semua verifikasi, menunggu TTE", $document);
                $document->pengusul?->notify(new \App\Notifications\DokumenLolosVerifikasi($document));
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
            $document->pengusul?->notify(new \App\Notifications\DokumenPerluRevisi($document, $catatan));

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

            $currentVersion = $document->currentVersion;
            $filePath = storage_path('app/' . $currentVersion->file_path);

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
                    'signer_role'  => $user->getRoleNames()->first(),
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

            $document->pengusul?->notify(new \App\Notifications\DokumenDitandatangani($document));

            return $document->fresh();
        });
    }

    /**
     * Publikasikan dokumen.
     */
    public function publikasi(Document $document): Document
    {
        abort_unless($document->status === Document::STATUS_DITANDATANGANI, 403, 'Hanya dokumen yang sudah ditandatangani yang dapat dipublikasikan.');

        $document->update([
            'status'            => Document::STATUS_DIPUBLIKASIKAN,
            'dipublikasikan_at' => now(),
        ]);

        AuditLog::catat('publikasi', "Dokumen dipublikasikan: {$document->nomor_surat}", $document);

        return $document->fresh();
    }

    /**
     * Generate nomor surat dengan atomic counter.
     */
    private function generateNomorSurat(Document $document): string
    {
        $type  = $document->documentType;
        $unit  = $document->unit;
        $tahun = (int) now()->format('Y');

        $nomorUrut = NumberingSequence::getNextNomor($type->id, $unit->id, $tahun);

        return $type->generateNomor($unit, $nomorUrut, now());
    }
}
