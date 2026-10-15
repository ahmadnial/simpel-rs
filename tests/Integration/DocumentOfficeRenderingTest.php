<?php

namespace Tests\Integration;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Unit;
use App\Models\User;
use App\Services\DocumentPdfService;
use App\Services\DocxTemplateProcessor;
use App\Services\WordDocumentNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\WordFixtures;
use Tests\TestCase;

class DocumentOfficeRenderingTest extends TestCase
{
    use WordFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('RUN_OFFICE_TESTS')) {
            $this->markTestSkipped('Set RUN_OFFICE_TESTS=1 to test installed LibreOffice and Poppler.');
        }
        Storage::fake('local');
        config(['documents.renderer' => 'libreoffice', 'documents.conversion_timeout' => 60]);
    }

    public function test_real_pdf_preserves_custom_landscape_page_and_marker_text_positions(): void
    {
        $file = $this->wordUpload('<w:p><w:r><w:rPr><w:rFonts w:ascii="Arial"/><w:sz w:val="24"/></w:rPr><w:t>Sebelum ${tanggal_draft} sesudah</w:t></w:r></w:p><w:p><w:r><w:t>Baris berikutnya</w:t></w:r></w:p><w:p><w:r><w:t>${qr_code}</w:t></w:r></w:p>');
        $document = new Document(['judul' => 'Uji layout', 'status' => 'draft']);
        $document->created_at = '2026-10-15 10:00:00';
        $document->setRelation('signature', null)->setRelation('pengusul', new User(['name' => 'Pengusul']))->setRelation('unit', new Unit(['nama' => 'Unit']));
        $processor = app(DocxTemplateProcessor::class);
        $renderer = app(DocumentPdfService::class);
        $plain = $renderer->render($processor->process($file->getRealPath(), $document, false), $document, new DocumentVersion);
        $marked = $renderer->render($processor->process($file->getRealPath(), $document, true), $document, new DocumentVersion);
        File::copy($marked, sys_get_temp_dir().'/simpel-marked-preview.pdf');
        File::copy($plain, sys_get_temp_dir().'/simpel-clean-preview.pdf');
        $plainBoxes = $this->boxes($plain);
        $markedBoxes = $this->boxes($marked);
        $this->assertSame($plainBoxes, $markedBoxes, 'Preview markers must not reflow text or change page dimensions.');
        $this->assertStringContainsString('width="936.000000" height="612.000000"', $plainBoxes);
        $this->assertStringContainsString('berikutnya', $plainBoxes);
    }

    public function test_real_legacy_doc_round_trip_is_accepted_and_renders(): void
    {
        $directory = Storage::disk('local')->path('legacy-test');
        File::ensureDirectoryExists($directory);
        $source = $directory.'/source.docx';
        File::copy($this->wordUpload()->getRealPath(), $source);
        $process = new Process([app(DocumentPdfService::class)->libreOfficeBinary(), '-env:UserInstallation=file://'.$directory.'/profile', '--headless', '--convert-to', 'doc:MS Word 97', '--outdir', $directory, $source]);
        $process->setTimeout(20)->mustRun();
        $docPath = $directory.'/source.doc';
        $this->assertFileExists($docPath);
        $normalizer = app(WordDocumentNormalizer::class);
        $result = $normalizer->normalize(new UploadedFile($docPath, 'NASKAH.DOC', null, null, true));
        try {
            $pdf = app(DocumentPdfService::class)->render($result['file']->getRealPath(), new Document, new DocumentVersion);
            $this->assertStringContainsString('Naskah', $this->boxes($pdf));
        } finally {
            $normalizer->cleanup($result['cleanup_dir']);
        }
    }

    private function boxes(string $pdf): string
    {
        $process = new Process(['pdftotext', '-bbox', $pdf, '-']);
        $process->setTimeout(10)->mustRun();
        // Metadata differs between conversions; compare page content and coordinates.
        preg_match('#<doc>(.*)</doc>#s', $process->getOutput(), $match);

        return $match[1];
    }
}
