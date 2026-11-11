<?php

namespace Tests\Unit;

use App\Exceptions\DocumentConversionException;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\DocumentPdfService;
use App\Services\DocumentQrOverlay;
use App\Services\DocxTemplateProcessor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WordFixtures;
use Tests\TestCase;

class DocumentPdfServiceTest extends TestCase
{
    use WordFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['documents.renderer' => 'gotenberg']);
    }

    public function test_same_input_is_converted_once_and_renderer_revision_invalidates_cache(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.4 test PDF', 200)]);
        $source = $this->wordUpload()->getRealPath();
        $service = app(DocumentPdfService::class);
        $first = $service->render($source, new Document, new DocumentVersion);
        $this->assertSame($first, $service->render($source, new Document, new DocumentVersion));
        Http::assertSentCount(1);
        config(['documents.render_revision' => 'new-fonts']);
        $this->assertNotSame($first, $service->render($source, new Document, new DocumentVersion));
        Http::assertSentCount(2);
    }

    public function test_converter_failure_does_not_create_a_fallback_pdf(): void
    {
        Http::fake(['*' => Http::response('service down', 503)]);
        try {
            app(DocumentPdfService::class)->render($this->wordUpload()->getRealPath(), new Document, new DocumentVersion);
            $this->fail('Conversion failure must be surfaced.');
        } catch (DocumentConversionException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame([], Storage::disk('local')->allFiles('pdf_cache'));
            Http::assertSentCount(1);
        }
    }

    public function test_successful_http_response_containing_html_is_not_cached_as_pdf(): void
    {
        Http::fake(['*' => Http::response('<html>Error</html>', 200)]);
        $this->expectException(DocumentConversionException::class);
        $this->expectExceptionMessage('PDF yang valid');
        app(DocumentPdfService::class)->render($this->wordUpload()->getRealPath(), new Document, new DocumentVersion);
    }

    public function test_only_completed_overlay_is_cached_and_failed_overlay_releases_lock(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.4 before QR', 200)]);
        $attempts = 0;
        $this->mock(DocumentQrOverlay::class)->shouldReceive('apply')->twice()->andReturnUsing(function ($source, $temporary) use (&$attempts) {
            if (++$attempts === 1) {
                throw new DocumentConversionException('Ruang QR tidak cukup.', statusCode: 422);
            }
            file_put_contents($temporary, '%PDF-1.4 complete QR');
        });
        $source = $this->wordUpload()->getRealPath();
        $hash = hash_file('sha256', $source);
        $service = app(DocumentPdfService::class);
        try {
            $service->render($source, new Document, new DocumentVersion);
            $this->fail('An incomplete overlay cannot enter the cache.');
        } catch (DocumentConversionException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame([], Storage::disk('local')->allFiles('pdf_cache'));
        }
        $pdf = $service->render($source, new Document, new DocumentVersion);
        $this->assertSame('%PDF-1.4 complete QR', file_get_contents($pdf));
        $this->assertSame($pdf, $service->render($source, new Document, new DocumentVersion));
        $this->assertCount(1, Storage::disk('local')->allFiles('pdf_cache'));
        $this->assertSame($hash, hash_file('sha256', $source));
        Http::assertSentCount(2);
    }

    public function test_missing_overlay_runtime_fails_without_serving_a_pdf_without_qr(): void
    {
        config(['documents.pdf_python' => '/missing-simpel-python']);
        Http::fake(['*' => Http::response('%PDF-1.4 before QR', 200)]);
        $file = $this->wordUpload('<w:p><w:r><w:t>${qr_code}</w:t></w:r></w:p>');
        $document = (new Document(['judul' => 'QC']))->setRelation('signature', null)->setRelation('pengusul', null)->setRelation('unit', null);
        $processed = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $document);
        try {
            app(DocumentPdfService::class)->render($processed, $document, new DocumentVersion);
            $this->fail('Unavailable QR runtime must not silently drop the QR.');
        } catch (DocumentConversionException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame([], Storage::disk('local')->allFiles('pdf_cache'));
        }
    }

    public function test_warning_metadata_survives_a_buffer_boundary_and_invalid_pages_are_ignored(): void
    {
        $service = app(DocumentPdfService::class);
        $path = Storage::disk('local')->path('metadata.pdf');
        Storage::disk('local')->put('metadata.pdf', str_repeat(' ', 65530).'/SimpelQrWarnings ('.base64_encode('[1,2]').')');
        $this->assertCount(1, $service->warnings($path));
        $this->assertStringContainsString('halaman 1, 2', $service->warnings($path)[0]);
        foreach ([[], ['<script>'], [-1], array_fill(0, 31, 1)] as $invalid) {
            Storage::disk('local')->put('metadata.pdf', '/SimpelQrWarnings ('.base64_encode(json_encode($invalid)).')');
            $this->assertSame([], $service->warnings($path));
        }
    }
}
