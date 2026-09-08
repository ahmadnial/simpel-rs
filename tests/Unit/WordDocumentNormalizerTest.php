<?php

namespace Tests\Unit;

use App\Services\WordDocumentNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class WordDocumentNormalizerTest extends TestCase
{
    public function test_docx_is_accepted_without_conversion(): void
    {
        $upload = $this->validDocxUpload('hasil-google-docs.docx');

        try {
            $result = (new WordDocumentNormalizer)->normalize($upload);

            $this->assertSame($upload, $result['file']);
            $this->assertSame('hasil-google-docs.docx', $result['stored_name']);
            $this->assertNull($result['cleanup_dir']);
        } finally {
            @unlink($upload->getRealPath());
        }
    }

    public function test_legacy_doc_is_converted_and_stored_with_docx_extension(): void
    {
        $normalizer = new class extends WordDocumentNormalizer
        {
            protected function convertDocToDocx(string $sourcePath, string $outputDir, string $profileDir): bool
            {
                $zip = new ZipArchive;
                $zip->open($outputDir.'/source.docx', ZipArchive::CREATE | ZipArchive::OVERWRITE);
                $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
                $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p/></w:body></w:document>');
                $zip->close();

                return true;
            }
        };
        $upload = UploadedFile::fake()->createWithContent('surat-ms-word.doc', 'legacy-word-content');

        $result = $normalizer->normalize($upload);

        try {
            $this->assertSame('docx', $result['file']->getClientOriginalExtension());
            $this->assertSame('surat-ms-word.docx', $result['stored_name']);
            $this->assertFileExists($result['file']->getRealPath());
        } finally {
            $cleanupDir = $result['cleanup_dir'];
            $normalizer->cleanup($cleanupDir);
        }

        $this->assertDirectoryDoesNotExist($cleanupDir);
    }

    public function test_non_word_extension_is_rejected(): void
    {
        $upload = UploadedFile::fake()->create('surat.pdf', 12, 'application/pdf');

        try {
            (new WordDocumentNormalizer)->normalize($upload);
            $this->fail('PDF seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file_dokumen', $exception->errors());
        }
    }

    public function test_corrupt_docx_is_rejected_before_entering_workflow(): void
    {
        $upload = UploadedFile::fake()->createWithContent('rusak.docx', 'not-a-zip-document');

        $this->expectException(ValidationException::class);

        (new WordDocumentNormalizer)->normalize($upload);
    }

    private function validDocxUpload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'valid_docx_');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p/></w:body></w:document>');
        $zip->close();

        return new UploadedFile(
            $path,
            $name,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        );
    }
}
