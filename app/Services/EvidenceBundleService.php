<?php

namespace App\Services;

use App\Support\EvidenceSignature;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class EvidenceBundleService
{
    public function __construct(private readonly CanonicalJson $canonicalJson) {}

    /** @return array{path:string,hash:string,size:int} */
    public function build(
        string $evidenceUuid,
        string $pdfPath,
        string $canonicalManifest,
        array $otpReceipt,
        EvidenceSignature $signature,
    ): array {
        abort_unless(is_file($pdfPath) && filesize($pdfPath) > 0, 500, 'PDF evidence bundle tidak tersedia.');
        $manifestHash = hash('sha256', $canonicalManifest);
        $signatureJson = $this->canonicalJson->encode([
            'algorithm' => $signature->key->algorithm,
            'key_fingerprint' => $signature->key->fingerprint,
            'key_id' => $signature->key->keyId,
            'manifest_hash' => $manifestHash,
            'signature' => $signature->signature,
        ]);
        $keyJson = $this->canonicalJson->encode($signature->key->toArray());
        $receiptJson = $this->canonicalJson->encode($otpReceipt);
        $report = $this->verificationReport($evidenceUuid, $manifestHash, hash_file('sha256', $pdfPath), $signature->key->fingerprint);
        $readme = "SIMPEL-RS Evidence Bundle v2\n\nVerifikasi dengan public key yang diperoleh dari kanal resmi institusi:\nphp artisan evidence:verify-bundle <bundle.zip> --public-key=<public-key.json> --json\n\nProfil ini adalah TTE Internal Terverifikasi—OTP, non-PSrE.\n";

        $entries = [
            'README.txt' => $readme,
            'evidence-manifest.json' => $canonicalManifest,
            'institution-signature.json' => $signatureJson,
            'otp-receipt.json' => $receiptJson,
            'public-key.json' => $keyJson,
            'verification-report.html' => $report,
        ];
        ksort($entries, SORT_STRING);

        $relativePath = "evidence-bundles/{$evidenceUuid}.zip";
        $absolutePath = Storage::disk('local')->path($relativePath);
        $directory = dirname($absolutePath);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Direktori evidence bundle tidak dapat dibuat.');
        }
        $temporaryPath = $absolutePath.'.tmp';
        $zip = new ZipArchive;
        abort_unless($zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'Evidence ZIP tidak dapat dibuat.');
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
            $this->normalizeEntry($zip, $name);
        }
        $zip->addFile($pdfPath, 'document.pdf');
        $this->normalizeEntry($zip, 'document.pdf');
        abort_unless($zip->close(), 500, 'Evidence ZIP gagal ditutup.');
        abort_unless(rename($temporaryPath, $absolutePath), 500, 'Evidence ZIP gagal dipromosikan.');

        return ['path' => $relativePath, 'hash' => hash_file('sha256', $absolutePath), 'size' => filesize($absolutePath)];
    }

    public function publicKeyJson(EvidenceSignature $signature): string
    {
        return $this->canonicalJson->encode($signature->key->toArray());
    }

    private function normalizeEntry(ZipArchive $zip, string $name): void
    {
        $zip->setMtimeName($name, 315532800);
        $zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 9);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100640 << 16);
    }

    private function verificationReport(string $evidenceUuid, string $manifestHash, string $pdfHash, string $fingerprint): string
    {
        return '<!doctype html><html lang="id"><meta charset="utf-8"><title>Laporan Verifikasi Evidence</title>'
            .'<h1>TTE Internal Terverifikasi—OTP</h1><p>Non-PSrE; assurance identitas OTP berada pada level menengah.</p>'
            .'<dl><dt>Evidence ID</dt><dd>'.htmlspecialchars($evidenceUuid).'</dd>'
            .'<dt>Manifest SHA-256</dt><dd>'.htmlspecialchars($manifestHash).'</dd>'
            .'<dt>PDF SHA-256</dt><dd>'.htmlspecialchars($pdfHash).'</dd>'
            .'<dt>Key fingerprint</dt><dd>'.htmlspecialchars($fingerprint).'</dd></dl></html>';
    }
}
