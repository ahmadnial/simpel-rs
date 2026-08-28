<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DocumentPdfService
{
    public function render(string $docxPath, Document $document, DocumentVersion $version): string
    {
        $cacheDir = storage_path('app/private/pdf_cache');
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $cacheKey = md5_file($docxPath).'_'.($document->updated_at?->timestamp ?? 0).'_'.($document->signature?->id ?? 0);
        $pdfPath = $cacheDir."/pdf_{$document->id}_{$version->id}_{$cacheKey}.pdf";
        if (is_file($pdfPath) && filesize($pdfPath) > 0) {
            return $pdfPath;
        }

        if ($this->renderWithGotenberg($docxPath, $pdfPath) || $this->renderWithLibreOffice($docxPath, $pdfPath)) {
            return $pdfPath;
        }

        $parser = new DocxParserService();
        $signature = $document->signature;
        $signer = $signature?->penandatangan
            ?? User::role('penandatangan')->first()
            ?? $document->pengusul;
        $qrCodeBase64 = null;

        if ($signature) {
            try {
                $options = new \chillerlan\QRCode\QROptions([
                    'outputInterface' => \chillerlan\QRCode\Output\QRGdImagePNG::class,
                    'scale' => 5,
                    'addQuietzone' => false,
                ]);
                $qrCodeBase64 = (new \chillerlan\QRCode\QRCode($options))->render(route('public.verify', $signature->qr_token));
            } catch (\Throwable) {
            }
        }

        Pdf::loadView('pdf.naskah', [
            'document' => $document,
            'version' => $version,
            'bodyHtml' => $parser->parseToHtml($docxPath, $document),
            'signature' => $signature,
            'penandatanganUser' => $signer,
            'qrCodeBase64' => $qrCodeBase64,
        ])->setPaper('A4', 'portrait')
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true)
            ->save($pdfPath);

        abort_unless(is_file($pdfPath) && filesize($pdfPath) > 0, 500, 'PDF final gagal dibuat. Pengesahan dibatalkan.');

        return $pdfPath;
    }

    public function persistOfficial(Document $document, DocumentVersion $version, string $renderedPdfPath, string $token): array
    {
        abort_unless(is_file($renderedPdfPath) && filesize($renderedPdfPath) > 0, 500, 'PDF final tidak tersedia.');

        $relativePath = "documents/{$document->id}/signed/{$token}.pdf";
        Storage::disk('local')->put($relativePath, file_get_contents($renderedPdfPath));
        $officialPath = Storage::disk('local')->path($relativePath);

        return [
            'path' => $relativePath,
            'hash' => hash_file('sha256', $officialPath),
            'size' => filesize($officialPath),
        ];
    }

    private function renderWithGotenberg(string $docxPath, string $pdfPath): bool
    {
        try {
            $url = rtrim((string) env('GOTENBERG_URL', 'http://localhost:3000'), '/');
            $response = Http::timeout(10)->attach('files', file_get_contents($docxPath), basename($docxPath))
                ->post($url.'/forms/libreoffice/convert');
            if ($response->successful() && str_starts_with($response->body(), '%PDF')) {
                file_put_contents($pdfPath, $response->body());
                return true;
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private function renderWithLibreOffice(string $docxPath, string $pdfPath): bool
    {
        $binary = env('LIBREOFFICE_PATH');
        foreach ([$binary, '/Applications/LibreOffice.app/Contents/MacOS/soffice', '/usr/bin/soffice', '/usr/bin/libreoffice'] as $candidate) {
            if ($candidate && is_file($candidate)) {
                $binary = $candidate;
                break;
            }
        }
        if (!$binary || !is_file($binary)) {
            return false;
        }

        $tempDir = storage_path('app/private/temp_pdf_'.uniqid());
        $profileDir = storage_path('app/private/soffice_prof_'.uniqid());
        mkdir($tempDir, 0755, true);
        mkdir($profileDir, 0755, true);
        $command = escapeshellarg($binary).' '.escapeshellarg('-env:UserInstallation=file://'.$profileDir)
            .' --headless --convert-to pdf --outdir '.escapeshellarg($tempDir).' '.escapeshellarg($docxPath);
        exec($command, $output, $exitCode);
        $generated = $tempDir.'/'.pathinfo($docxPath, PATHINFO_FILENAME).'.pdf';
        $success = $exitCode === 0 && is_file($generated) && filesize($generated) > 0;
        if ($success) {
            copy($generated, $pdfPath);
        }
        if (is_file($generated)) {
            unlink($generated);
        }
        @rmdir($tempDir);
        @rmdir($profileDir);

        return $success;
    }
}
