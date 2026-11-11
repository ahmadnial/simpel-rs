<?php

namespace App\Services;

use App\Exceptions\DocumentConversionException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

/** Adds QR ink after Office layout; never reflows the source document. */
class DocumentQrOverlay
{
    public function apply(string $docx, string $pdf): void
    {
        $zip = new ZipArchive;
        if ($zip->open($docx) !== true) {
            throw new DocumentConversionException('Salinan DOCX tidak dapat dibaca.');
        }
        try {
            $raw = $zip->getFromName(DocxTemplateProcessor::QR_MANIFEST, 65537);
            if ($raw === false) {
                return;
            }
            $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (strlen($raw) > 65536 || ($manifest['version'] ?? null) !== 1
                || ! preg_match('#^word/media/(simpel-)+qr\.png$#', $manifest['image'] ?? '')
                || ! is_array($manifest['markers'] ?? null) || ! $manifest['markers']) {
                throw new \RuntimeException('Invalid QR manifest.');
            }
            foreach ($manifest['markers'] as $marker) {
                if (! is_string($marker) || ! preg_match('#^https://simpel-preview\.invalid/qr/[a-f0-9]{32}/\d+$#D', $marker)) {
                    throw new \RuntimeException('Invalid QR marker.');
                }
            }
            $png = $zip->getFromName($manifest['image'], 1048577);
            if ($png === false || strlen($png) > 1048576 || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
                throw new \RuntimeException('Invalid QR image.');
            }
        } finally {
            $zip->close();
        }

        $directory = dirname($pdf).'/qr_'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        try {
            $overlay = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false]));
            $overlay->setPaper([0, 0, 65, 65]);
            $overlay->loadHtml('<style>@page{margin:0}body{margin:0;font-size:0}img{width:65pt;height:65pt}</style><img src="data:image/png;base64,'.base64_encode($png).'">');
            $overlay->render();
            File::put($directory.'/stamp.pdf', $overlay->output());
            File::put($directory.'/markers.json', json_encode($manifest['markers'], JSON_THROW_ON_ERROR));
            $process = new Process([
                config('documents.pdf_python', base_path('.venv-pdf/bin/python')), base_path('scripts/pdf_qr_overlay.py'),
                $pdf, $directory.'/stamp.pdf', $directory.'/result.pdf', $directory.'/markers.json',
                $this->popplerExecutable('pdftotext_path'),
                $this->popplerExecutable('pdftoppm_path'),
            ]);
            $process->setTimeout((int) config('documents.conversion_timeout'));
            $process->run();
            $result = json_decode($process->getOutput(), true);
            if (! $process->isSuccessful() || ($result['ok'] ?? false) !== true) {
                $page = is_int($result['page'] ?? null) ? ' pada halaman '.$result['page'] : '';
                if (($result['error'] ?? null) === 'layout') {
                    throw new DocumentConversionException('Posisi QR'.$page.' tidak dapat ditentukan dengan pasti atau geometri halamannya tidak didukung. Periksa variabel QR pada template lalu unggah ulang.', statusCode: 422);
                }
                logger()->warning('PDF QR overlay failed.', [
                    'exit_code' => $process->getExitCode(), 'reason' => $result['error'] ?? 'runtime',
                    'exception_type' => $result['exception_type'] ?? null, 'script_line' => $result['script_line'] ?? null,
                ]);
                throw new DocumentConversionException('QR belum dapat ditempatkan dengan aman. Hubungi administrator untuk memeriksa layanan PDF.');
            }
            if (! rename($directory.'/result.pdf', $pdf)) {
                throw new DocumentConversionException('PDF dengan QR belum dapat disimpan.');
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function popplerExecutable(string $setting): string
    {
        // A previous release's config cache does not contain the new QR keys yet.
        $configured = (string) config('documents.'.$setting, str_replace('_path', '', $setting));
        $binary = is_file($configured) && is_executable($configured)
            ? $configured
            : (new ExecutableFinder)->find($configured, null, ['/usr/local/bin', '/opt/homebrew/bin', '/usr/bin']);
        if ($binary === null) {
            throw new DocumentConversionException('Layanan '.str_replace('_path', '', $setting).' belum tersedia. Administrator perlu memeriksa konfigurasi layanan PDF.');
        }

        // HTTP subprocesses may inherit no PATH. Pass the absolute executable
        // through to Python instead of relying on its environment's search path.
        return str_starts_with($binary, DIRECTORY_SEPARATOR) ? $binary : getcwd().DIRECTORY_SEPARATOR.$binary;
    }
}
