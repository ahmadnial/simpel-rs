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

            $firstStep = $template?->steps()->first();

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

            // Cek apakah ada level VERIFIKASI berikutnya (bukan step penandatangan)
            $template = $document->workflowTemplate;
            $nextVerificationStep = $template?->steps()
                ->where('urutan', '>', $verification->level)
                ->where('tipe', 'verifikasi')
                ->first();

            if ($nextVerificationStep) {
                // Masih ada level verifikasi berikutnya
                $nextLevel = $verification->level + 1;
                DocumentVerification::firstOrCreate([
                    'document_id'         => $document->id,
                    'level'               => $nextLevel,
                ], [
                    'document_version_id' => $verification->document_version_id,
                    'workflow_step_id'    => $nextVerificationStep->id,
                    'status'              => DocumentVerification::STATUS_MENUNGGU,
                    'batas_waktu'         => now()->addDays($nextVerificationStep->sla_hari_kerja ?? 2),
                ]);

                $document->update([
                    'status'       => Document::STATUS_VERIFIKASI,
                    'current_step' => $nextVerificationStep->urutan,
                ]);
            } else {
                // Semua verifikasi selesai → Menunggu TTD Direktur / Penandatangan
                $penandatanganStep = $template?->steps()->where('tipe', 'penandatangan')->first();
                $document->update([
                    'status'       => Document::STATUS_MENUNGGU_TTD,
                    'current_step' => $penandatanganStep?->urutan ?? ($verification->level + 1),
                ]);
                AuditLog::catat('lolos_verifikasi', "Dokumen lolos semua verifikasi, menunggu TTE Direktur", $document);
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
}
