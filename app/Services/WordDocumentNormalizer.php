<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use ZipArchive;

class WordDocumentNormalizer
{
    /**
     * Ubah format Word lama (.doc) menjadi DOCX agar seluruh pipeline internal
     * (preview, OnlyOffice, penyisipan QR, dan PDF final) memakai satu format.
     *
     * @return array{file: UploadedFile, stored_name: string, cleanup_dir: ?string}
     */
    public function normalize(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['doc', 'docx'], true)) {
            throw ValidationException::withMessages([
                'file_dokumen' => 'Berkas harus berupa dokumen Microsoft Word berformat .doc atau .docx.',
            ]);
        }

        if ($extension === 'docx') {
            $this->assertProcessableDocx($file->getRealPath());

            return [
                'file' => $file,
                'stored_name' => $file->getClientOriginalName(),
                'cleanup_dir' => null,
            ];
        }

        $tempDir = storage_path('app/private/word_import_'.bin2hex(random_bytes(8)));
        $profileDir = $tempDir.'/profile';
        $outputDir = $tempDir.'/output';
        mkdir($profileDir, 0755, true);
        mkdir($outputDir, 0755, true);

        $sourcePath = $tempDir.'/source.doc';
        if (! copy($file->getRealPath(), $sourcePath)) {
            $this->removeDirectory($tempDir);
            throw ValidationException::withMessages([
                'file_dokumen' => 'Berkas .doc tidak dapat dibaca. Silakan unduh atau simpan ulang dokumen lalu coba lagi.',
            ]);
        }

        try {
            $converted = $this->convertDocToDocx($sourcePath, $outputDir, $profileDir);
        } catch (\Throwable) {
            $this->removeDirectory($tempDir);
            throw ValidationException::withMessages([
                'file_dokumen' => 'Konversi berkas .doc gagal. Pastikan dokumen tidak rusak atau terlindungi kata sandi.',
            ]);
        }

        $convertedPath = $outputDir.'/source.docx';
        if (! $converted || ! is_file($convertedPath) || filesize($convertedPath) === 0) {
            $this->removeDirectory($tempDir);
            throw ValidationException::withMessages([
                'file_dokumen' => 'Berkas .doc tidak dapat dikonversi. Pastikan dokumen valid, tidak rusak, dan tidak terlindungi kata sandi.',
            ]);
        }

        try {
            $this->assertProcessableDocx($convertedPath);
        } catch (ValidationException $exception) {
            $this->removeDirectory($tempDir);
            throw ValidationException::withMessages([
                'file_dokumen' => 'Hasil konversi berkas .doc tidak valid. Pastikan dokumen tidak rusak atau terlindungi kata sandi.',
            ]);
        }

        $storedName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.docx';

        return [
            'file' => new UploadedFile(
                $convertedPath,
                $storedName,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true,
            ),
            'stored_name' => $storedName,
            'cleanup_dir' => $tempDir,
        ];
    }

    public function cleanup(?string $directory): void
    {
        if ($directory !== null) {
            $this->removeDirectory($directory);
        }
    }

    public function assertProcessableDocx(string $path): void
    {
        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw ValidationException::withMessages([
                'file_dokumen' => 'Berkas DOCX tidak valid, rusak, atau terlindungi kata sandi.',
            ]);
        }

        try {
            if ($zip->numFiles > 5000
                || $zip->locateName('[Content_Types].xml') === false
                || $zip->locateName('word/document.xml') === false) {
                throw ValidationException::withMessages([
                    'file_dokumen' => 'Struktur berkas DOCX tidak valid atau tidak lengkap.',
                ]);
            }

            $totalUncompressed = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                $size = (int) ($stat['size'] ?? 0);
                $totalUncompressed += $size;

                if ($name === ''
                    || str_starts_with($name, '/')
                    || preg_match('#(^|/)\.\.(/|$)#', $name)
                    || $size > 25 * 1024 * 1024
                    || $totalUncompressed > 100 * 1024 * 1024) {
                    throw ValidationException::withMessages([
                        'file_dokumen' => 'Isi berkas DOCX tidak aman atau terlalu besar untuk diproses.',
                    ]);
                }

                $lowerName = strtolower($name);
                if ($lowerName === 'encryptedpackage'
                    || $lowerName === 'word/vbaproject.bin'
                    || str_starts_with($lowerName, 'word/embeddings/')) {
                    throw ValidationException::withMessages([
                        'file_dokumen' => 'DOCX terenkripsi, bermakro, atau memiliki objek tertanam tidak dapat diproses.',
                    ]);
                }
            }

            $documentXml = $zip->getFromName('word/document.xml');
            if (! is_string($documentXml) || $documentXml === '') {
                throw ValidationException::withMessages([
                    'file_dokumen' => 'Isi utama berkas DOCX tidak dapat dibaca.',
                ]);
            }

            $dom = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $validXml = $dom->loadXML($documentXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            if (! $validXml || $dom->documentElement?->localName !== 'document') {
                throw ValidationException::withMessages([
                    'file_dokumen' => 'Isi utama berkas DOCX tidak valid.',
                ]);
            }
        } finally {
            $zip->close();
        }
    }

    protected function convertDocToDocx(string $sourcePath, string $outputDir, string $profileDir): bool
    {
        $binary = $this->libreOfficeBinary();
        if ($binary === null) {
            throw new \RuntimeException('LibreOffice tidak tersedia.');
        }

        $process = new Process([
            $binary,
            '-env:UserInstallation=file://'.$profileDir,
            '--headless',
            '--convert-to',
            'docx',
            '--outdir',
            $outputDir,
            $sourcePath,
        ]);
        $process->setTimeout(60);
        $process->run();

        return $process->isSuccessful();
    }

    private function libreOfficeBinary(): ?string
    {
        foreach (array_filter([
            env('LIBREOFFICE_PATH'),
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
            '/usr/bin/soffice',
            '/usr/bin/libreoffice',
        ]) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
