<?php

namespace Tests\Unit;

use App\Exceptions\DocumentConversionException;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\DocumentPdfService;
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
}
