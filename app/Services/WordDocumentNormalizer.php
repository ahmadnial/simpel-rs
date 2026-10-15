<?php

namespace App\Services;

use App\Exceptions\DocumentConversionException;
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

        $header = file_get_contents($file->getRealPath(), false, null, 0, 8);
        $isCompound = $header === hex2bin('d0cf11e0a1b11ae1');
        if ($extension === 'docx' && $isCompound) {
            throw ValidationException::withMessages([
                'file_dokumen' => 'Berkas DOCX terenkripsi atau berisi format DOC lama. Buka di Word, hapus password jika ada, lalu simpan sebagai DOCX.',
            ]);
        }
        if ($extension === 'doc' && ! $isCompound) {
            throw ValidationException::withMessages([
                'file_dokumen' => 'Ekstensi berkas .doc, tetapi isinya bukan format Word DOC biner. Simpan ulang melalui Word sebagai .doc atau .docx; jangan hanya mengganti nama ekstensi.',
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
        } catch (DocumentConversionException $exception) {
            $this->removeDirectory($tempDir);
            throw $exception;
        } catch (\Throwable $exception) {
            $this->removeDirectory($tempDir);
            report($exception);
            throw new DocumentConversionException('Layanan konversi DOC belum berhasil menyelesaikan proses. Coba lagi atau hubungi administrator.', $exception);
        }

        $convertedPath = $outputDir.'/source.docx';
        if (! $converted || ! is_file($convertedPath) || filesize($convertedPath) === 0) {
            $this->removeDirectory($tempDir);
            throw ValidationException::withMessages([
                'file_dokumen' => 'Berkas DOC belum dapat dikonversi. Penyebab belum dapat dipastikan. Coba buka dan simpan ulang di Word; hapus password jika ada.',
            ]);
        }

        try {
            $this->assertProcessableDocx($convertedPath);
        } catch (ValidationException $exception) {
            $this->removeDirectory($tempDir);
            throw ValidationException::withMessages([
                'file_dokumen' => 'Hasil konversi DOC belum dapat diproses: '.implode(' ', $exception->errors()['file_dokumen'] ?? []),
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
                'file_dokumen' => 'Isi berkas tidak dapat dibaca sebagai paket DOCX. Buka dan simpan ulang sebagai DOCX melalui Word atau Google Docs.',
            ]);
        }

        try {
            if ($zip->numFiles > 5000) {
                throw ValidationException::withMessages(['file_dokumen' => 'Dokumen memuat lebih dari 5.000 bagian internal. Sederhanakan dokumen sebelum mengunggah.']);
            }
            if ($zip->locateName('[Content_Types].xml') === false
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
                    || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                    throw ValidationException::withMessages([
                        'file_dokumen' => 'Struktur jalur internal DOCX tidak valid. Buka dan simpan ulang berkas melalui Word.',
                    ]);
                }

                if ($size > 25 * 1024 * 1024 || $totalUncompressed > 100 * 1024 * 1024) {
                    throw ValidationException::withMessages([
                        'file_dokumen' => 'Isi DOCX setelah dibuka melebihi batas pemrosesan (25 MB per bagian atau 100 MB total). Kurangi ukuran gambar atau objek dalam dokumen.',
                    ]);
                }
                if (($stat['encryption_method'] ?? 0) !== 0) {
                    throw ValidationException::withMessages(['file_dokumen' => 'Paket DOCX menggunakan enkripsi. Hapus password sebelum mengunggah.']);
                }
                $lowerName = strtolower($name);
                $unsupported = match (true) {
                    $lowerName === 'encryptedpackage' => 'Dokumen menggunakan enkripsi. Hapus password sebelum mengunggah.',
                    $lowerName === 'word/vbaproject.bin' => 'Dokumen memuat macro. Simpan sebagai DOCX tanpa macro sebelum mengunggah.',
                    str_starts_with($lowerName, 'word/embeddings/') => 'Dokumen memuat objek tertanam, misalnya file Excel. Ubah objek menjadi tabel atau gambar sebelum mengunggah.',
                    default => null,
                };
                if ($unsupported !== null) {
                    throw ValidationException::withMessages(['file_dokumen' => $unsupported]);
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
            if (! $validXml || $dom->doctype !== null || $dom->documentElement?->localName !== 'document'
                || ! in_array($dom->documentElement?->namespaceURI, ['http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'http://purl.oclc.org/ooxml/wordprocessingml/main'], true)) {
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
        $binary = app(DocumentPdfService::class)->libreOfficeBinary();

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
        $process->setTimeout((int) config('documents.conversion_timeout'));
        $process->run();

        return $process->isSuccessful();
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
