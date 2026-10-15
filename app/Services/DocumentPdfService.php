<?php

namespace App\Services;

use App\Exceptions\DocumentConversionException;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class DocumentPdfService
{
    public function render(string $docxPath, Document $document, DocumentVersion $version): string
    {
        $key = hash('sha256', hash_file('sha256', $docxPath).'|'.config('documents.renderer').'|'.config('documents.render_revision'));
        $directory = Storage::disk('local')->path('pdf_cache');
        File::ensureDirectoryExists($directory);
        $pdfPath = $directory.'/office_'.$key.'.pdf';
        if (is_file($pdfPath) && filesize($pdfPath) > 0) {
            return $pdfPath;
        }

        // Concurrent viewers share one conversion. Partial output is never served.
        $lock = Cache::lock('document-pdf:'.$key, (int) config('documents.conversion_timeout') + 15);
        if (! $lock->get()) {
            throw new DocumentConversionException('PDF sedang diproses. Tunggu sebentar lalu muat ulang pratinjau.');
        }
        $temporary = $pdfPath.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (is_file($pdfPath) && filesize($pdfPath) > 0) {
                return $pdfPath;
            }
            $this->convert($docxPath, $temporary);
            if (! is_file($temporary) || file_get_contents($temporary, false, null, 0, 5) !== '%PDF-') {
                throw new DocumentConversionException('Layanan konversi tidak menghasilkan PDF yang valid. Coba lagi atau hubungi administrator.');
            }
            if (! rename($temporary, $pdfPath)) {
                throw new DocumentConversionException('PDF belum dapat disimpan. Hubungi administrator.');
            }

            return $pdfPath;
        } catch (DocumentConversionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw new DocumentConversionException(previous: $exception);
        } finally {
            File::delete($temporary);
            $lock->release();
        }
    }

    protected function convert(string $source, string $destination): void
    {
        if (config('documents.renderer') === 'gotenberg') {
            $response = Http::connectTimeout(5)->timeout((int) config('documents.conversion_timeout'))
                ->attach('files', file_get_contents($source), basename($source))
                ->post(rtrim(config('documents.gotenberg_url'), '/').'/forms/libreoffice/convert');
            if (! $response->successful()) {
                logger()->warning('Office PDF conversion failed.', ['renderer' => 'gotenberg', 'status' => $response->status()]);
                throw new DocumentConversionException('Layanan konversi gagal memproses dokumen. Penyebab belum dapat dipastikan; coba lagi atau hubungi administrator.');
            }
            File::put($destination, $response->body());

            return;
        }
        if (config('documents.renderer') !== 'libreoffice') {
            throw new DocumentConversionException('Konfigurasi layanan konversi belum benar. Hubungi administrator.');
        }

        $binary = $this->libreOfficeBinary();
        $directory = dirname($destination).'/work_'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        try {
            $process = new Process([
                $binary, '-env:UserInstallation=file://'.$directory.'/profile',
                '--headless', '--convert-to', 'pdf:writer_pdf_Export', '--outdir', $directory, $source,
            ]);
            $process->setTimeout((int) config('documents.conversion_timeout'));
            $process->run();
            $output = $directory.'/'.pathinfo($source, PATHINFO_FILENAME).'.pdf';
            if (! $process->isSuccessful() || ! is_file($output)) {
                logger()->warning('Office PDF conversion failed.', ['renderer' => 'libreoffice', 'exit_code' => $process->getExitCode()]);
                throw new DocumentConversionException('Konversi dokumen belum berhasil. Penyebab belum dapat dipastikan; coba simpan ulang melalui Word atau hubungi administrator.');
            }
            File::copy($output, $destination);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function libreOfficeBinary(): string
    {
        $configured = config('documents.libreoffice_path');
        $candidates = $configured ? [$configured] : ['/Applications/LibreOffice.app/Contents/MacOS/soffice', '/usr/bin/soffice', '/usr/bin/libreoffice'];
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        throw new DocumentConversionException('Layanan LibreOffice belum tersedia. Administrator perlu mengatur layanan konversi; berkas Anda belum dinyatakan rusak.');
    }

    public function persistOfficial(Document $document, DocumentVersion $version, string $renderedPdfPath, string $token): array
    {
        abort_unless(is_file($renderedPdfPath) && filesize($renderedPdfPath) > 0, 500, 'PDF final tidak tersedia.');
        $relativePath = "documents/{$document->id}/signed/{$token}.pdf";
        Storage::disk('local')->put($relativePath, file_get_contents($renderedPdfPath));
        $officialPath = Storage::disk('local')->path($relativePath);

        return ['path' => $relativePath, 'hash' => hash_file('sha256', $officialPath), 'size' => filesize($officialPath)];
    }
}
