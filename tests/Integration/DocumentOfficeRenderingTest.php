<?php

namespace Tests\Integration;

use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentVersion;
use App\Models\Unit;
use App\Models\User;
use App\Services\DocumentPdfService;
use App\Services\DocxStyleCompatibility;
use App\Services\DocxTemplateProcessor;
use App\Services\WordDocumentNormalizer;
use chillerlan\QRCode\QRCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $file = $this->wordUpload('<w:p><w:r><w:rPr><w:rFonts w:ascii="Arial"/><w:sz w:val="24"/></w:rPr><w:t>Sebelum ${tanggal_draft} sesudah</w:t></w:r></w:p><w:p><w:r><w:t>Baris berikutnya</w:t></w:r></w:p>'.str_repeat('<w:p/>', 4).'<w:p><w:r><w:t>${qr_code}</w:t></w:r></w:p>'.str_repeat('<w:p/>', 4));
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
        $this->assertPdfQr($marked, 'DOKUMEN DRAFT - BELUM DISAHKAN. Bukan bukti pengesahan.');
    }

    public static function httpRuntimeConfigurations(): array
    {
        return ['current config' => [false], 'previous release config without QR keys' => [true]];
    }

    #[DataProvider('httpRuntimeConfigurations')]
    public function test_http_overlay_renders_and_qr_scans_when_subprocess_path_is_missing(bool $previousReleaseConfig): void
    {
        $file = $this->wordUpload('<w:p><w:r><w:t>Uji HTTP ${tanggal_ttd}</w:t></w:r></w:p>'.str_repeat('<w:p/>', 4).'<w:p><w:r><w:t>${qr_code}</w:t></w:r></w:p>'.str_repeat('<w:p/>', 4));
        $processed = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $this->document(), true);
        $root = Storage::disk('local')->path('http-runtime');
        File::ensureDirectoryExists($root);
        $renderer = new class extends DocumentPdfService
        {
            public function raw(string $source, string $destination): void
            {
                $this->convert($source, $destination);
            }
        };
        $renderer->raw($processed, $root.'/before.pdf');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = new Process([PHP_BINARY, '-S', $address, base_path('tests/Support/qr_http_worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => base_path('bootstrap/cache/testing-config.php'),
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'PATH' => false,
            'DOCUMENT_PDF_PYTHON' => config('documents.pdf_python'),
            'DOCUMENT_PDFTOTEXT_PATH' => 'pdftotext', 'DOCUMENT_PDFTOPPM_PATH' => 'pdftoppm',
            'SIMPLE_QR_HTTP_DOCX' => $processed, 'SIMPLE_QR_HTTP_SOURCE_PDF' => $root.'/before.pdf',
            'SIMPLE_QR_HTTP_OUTPUT' => $root.'/after.pdf',
            'SIMPLE_QR_HTTP_OLD_CONFIG' => $previousReleaseConfig ? '1' : false,
        ]);
        $server->setTimeout(30)->start();
        try {
            $deadline = microtime(true) + 10;
            do {
                try {
                    if (Http::timeout(1)->get('http://'.$address.'/health')->body() === 'ready') {
                        break;
                    }
                } catch (ConnectionException) {
                    // Wait only for the owned local server to start listening.
                }
                $this->assertTrue(microtime(true) < $deadline && $server->isRunning(), $server->getErrorOutput());
                usleep(10000);
            } while (true);
            $response = Http::timeout(20)->get('http://'.$address.'/overlay?markers=1');
            $this->assertSame(200, $response->status(), $response->body());
            $this->assertSame(['ok' => true, 'sapi' => 'cli-server', 'parent_has_path' => false, 'pdf_header' => '%PDF-'], $response->json());
            $this->assertSame($this->boxes($root.'/before.pdf'), $this->boxes($root.'/after.pdf'));
            $this->assertPdfQr($root.'/after.pdf', 'DOKUMEN DRAFT - BELUM DISAHKAN. Bukan bukti pengesahan.');
        } finally {
            $server->stop();
        }
    }

    public function test_qr_keeps_source_pagination_and_geometry_in_draft_and_final(): void
    {
        $file = $this->wordUpload('<w:p><w:r><w:t>Direktur</w:t></w:r></w:p>'.str_repeat('<w:p/>', 4).'<w:p><w:r><w:t>${barcode_tte}</w:t></w:r></w:p>'.str_repeat('<w:p/>', 4).'<w:p><w:r><w:t>Nama penandatangan</w:t></w:r></w:p>');
        $hash = hash_file('sha256', $file->getRealPath());
        $document = $this->document();
        $renderer = app(DocumentPdfService::class);
        $baseline = $this->boxes($renderer->render($file->getRealPath(), $document, new DocumentVersion));
        foreach ([false, true] as $signed) {
            if ($signed) {
                $signature = new DocumentSignature(['ditandatangani_at' => '2026-11-11 10:00:00', 'qr_token' => '8a44bb01-3bb6-4b1f-9cc2-4b809b107100']);
                $signature->setRelation('penandatangan', new User(['name' => 'Direktur']));
                $document->setRelation('signature', $signature);
            }
            $processed = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $document);
            $pdf = $renderer->render($processed, $document, new DocumentVersion);
            $this->assertSame($baseline, $this->boxes($pdf));
            $this->assertPdfQr($pdf, $signed ? route('public.verify', $signature->qr_token) : 'DOKUMEN DRAFT - BELUM DISAHKAN. Bukan bukti pengesahan.');
        }
        $this->assertSame($hash, hash_file('sha256', $file->getRealPath()));
    }

    public function test_table_font_inheritance_renders_at_source_sizes_and_keeps_direct_overrides(): void
    {
        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $r = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $styles = '<w:styles xmlns:w="'.$w.'"><w:docDefaults><w:rPrDefault><w:rPr><w:sz w:val="24"/></w:rPr></w:rPrDefault></w:docDefaults>'
            .'<w:style w:type="table" w:styleId="Base"><w:name w:val="Base"/><w:pPr><w:spacing w:after="0"/></w:pPr><w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:style>'
            .'<w:style w:type="table" w:styleId="Inherited"><w:name w:val="Inherited"/><w:basedOn w:val="Base"/></w:style>'
            .'<w:style w:type="table" w:styleId="Child"><w:name w:val="Child"/><w:basedOn w:val="Base"/><w:rPr><w:sz w:val="24"/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="ParagraphOverride"><w:name w:val="ParagraphOverride"/><w:rPr><w:sz w:val="28"/></w:rPr></w:style></w:styles>';
        $body = '<w:tbl><w:tblPr><w:tblStyle w:val="Inherited"/></w:tblPr><w:tr><w:tc><w:p><w:r><w:t>Inherited10</w:t></w:r></w:p></w:tc></w:tr></w:tbl><w:p/>'
            .'<w:tbl><w:tblPr><w:tblStyle w:val="Child"/></w:tblPr><w:tr><w:tc><w:p><w:r><w:t>Child12</w:t></w:r></w:p><w:p><w:pPr><w:pStyle w:val="ParagraphOverride"/></w:pPr><w:r><w:t>Paragraph14</w:t></w:r></w:p><w:p><w:r><w:rPr><w:sz w:val="32"/></w:rPr><w:t>Run16</w:t></w:r></w:p></w:tc></w:tr></w:tbl><w:p/>';
        $file = $this->wordUpload($body, parts: [
            'word/styles.xml' => $styles,
            'word/_rels/document.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdStyles" Type="'.$r.'/styles" Target="styles.xml"/></Relationships>',
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
        ]);
        $document = $this->document();
        $processed = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $document);
        $pdf = app(DocumentPdfService::class)->render($processed, $document, new DocumentVersion);
        $process = new Process([config('documents.pdf_python'), '-c', <<<'PY'
import json, sys
from pypdf import PdfReader
found = {}
def visit(text, cm, tm, font, size):
    for name in ['Inherited10', 'Child12', 'Paragraph14', 'Run16']:
        if name in text:
            found[name] = size
for page in PdfReader(sys.argv[1]).pages:
    page.extract_text(visitor_text=visit)
print(json.dumps(found))
PY, $pdf]);
        $process->setTimeout(10)->mustRun();
        $this->assertEquals(['Inherited10' => 10, 'Child12' => 12, 'Paragraph14' => 14, 'Run16' => 16], json_decode($process->getOutput(), true));
    }

    public function test_insufficient_qr_space_still_renders_with_warning(): void
    {
        $file = $this->wordUpload('<w:p><w:r><w:t>Direktur ${qr_code} nama</w:t></w:r></w:p>');
        $document = $this->document();
        $processed = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $document);
        $renderer = app(DocumentPdfService::class);
        $pdf = $renderer->render($processed, $document, new DocumentVersion);
        $this->assertCount(1, $renderer->warnings($pdf));
        $this->assertStringContainsString('bertumpang tindih', implode(' ', $renderer->warnings($pdf)));
        $this->assertSame($pdf, $renderer->render($processed, $document, new DocumentVersion));
        $this->assertPdfQr($pdf, 'DOKUMEN DRAFT - BELUM DISAHKAN. Bukan bukti pengesahan.');
    }

    public function test_qr_in_a_bordered_table_keeps_cell_and_following_text_positions(): void
    {
        $body = '<w:p><w:r><w:t>Sebelum tabel</w:t></w:r></w:p><w:tbl><w:tblPr><w:tblW w:w="6000" w:type="dxa"/><w:tblBorders><w:top w:val="single"/><w:left w:val="single"/><w:bottom w:val="single"/><w:right w:val="single"/></w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="6000"/></w:tblGrid><w:tr><w:tc><w:tcPr><w:tcW w:w="6000" w:type="dxa"/></w:tcPr>'.str_repeat('<w:p/>', 4).'<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>${qr_code}</w:t></w:r></w:p>'.str_repeat('<w:p/>', 4).'</w:tc></w:tr></w:tbl><w:p><w:r><w:t>Sesudah tabel</w:t></w:r></w:p>';
        $file = $this->wordUpload($body);
        $document = $this->document();
        $renderer = app(DocumentPdfService::class);
        $original = $renderer->render($file->getRealPath(), $document, new DocumentVersion);
        $processed = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $document);
        $fixed = $renderer->render($processed, $document, new DocumentVersion);
        $this->assertSame($this->boxes($original), $this->boxes($fixed));
        $this->assertPdfQr($fixed, 'DOKUMEN DRAFT - BELUM DISAHKAN. Bukan bukti pengesahan.');
    }

    public function test_repeating_header_and_footer_qrs_keep_two_page_layout(): void
    {
        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $r = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $blank = str_repeat('<w:p/>', 3);
        $file = $this->wordUpload(parts: [
            'word/document.xml' => '<w:document xmlns:w="'.$w.'" xmlns:r="'.$r.'"><w:body><w:p><w:r><w:t>Halaman pertama</w:t><w:br w:type="page"/><w:t>Halaman kedua</w:t></w:r></w:p><w:sectPr><w:headerReference w:type="default" r:id="rIdHeader"/><w:footerReference w:type="default" r:id="rIdFooter"/><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="2880" w:right="1440" w:bottom="2880" w:left="1440" w:header="720" w:footer="720"/></w:sectPr></w:body></w:document>',
            'word/header1.xml' => '<w:hdr xmlns:w="'.$w.'">'.$blank.'<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>${qr_code}</w:t></w:r></w:p>'.$blank.'</w:hdr>',
            'word/footer1.xml' => '<w:ftr xmlns:w="'.$w.'"><w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>${barcode_tte}</w:t></w:r></w:p>'.$blank.'</w:ftr>',
            'word/_rels/document.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdHeader" Type="'.$r.'/header" Target="header1.xml"/><Relationship Id="rIdFooter" Type="'.$r.'/footer" Target="footer1.xml"/></Relationships>',
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>',
        ]);
        $document = $this->document();
        $renderer = app(DocumentPdfService::class);
        $original = $renderer->render($file->getRealPath(), $document, new DocumentVersion);
        $processed = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $document);
        $fixed = $renderer->render($processed, $document, new DocumentVersion);
        $this->assertCount(2, $this->geometry($fixed));
        $this->assertSame($this->boxes($original), $this->boxes($fixed));
        $this->assertSame(4, $this->assertPdfQr($fixed, 'DOKUMEN DRAFT - BELUM DISAHKAN. Bukan bukti pengesahan.'));
    }

    public function test_private_document_qr_preserves_normalized_office_pagination_and_geometry(): void
    {
        $source = getenv('OFFICE_LAYOUT_FIXTURE');
        if (! $source || ! is_file($source)) {
            $this->markTestSkipped('Set OFFICE_LAYOUT_FIXTURE to the private source DOCX; never commit its contents.');
        }
        $hash = hash_file('sha256', $source);
        $document = $this->document();
        $renderer = app(DocumentPdfService::class);
        // Isolate QR fidelity from Office import compatibility. A raw LibreOffice
        // export is not a Microsoft Word reference and misreads inherited styles.
        $control = Storage::disk('local')->path('normalized-control.docx');
        File::copy($source, $control);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($control));
        app(DocxStyleCompatibility::class)->apply($zip);
        $zip->close();
        $original = $renderer->render($control, $document, new DocumentVersion);
        $processed = app(DocxTemplateProcessor::class)->process($source, $document, false);
        $fixed = $renderer->render($processed, $document, new DocumentVersion);
        $before = $this->geometry($original);
        $after = $this->geometry($fixed);
        $this->assertCount(count($before), $after);
        $maxDelta = 0;
        foreach ($before as $index => $page) {
            $this->assertSame($page['size'], $after[$index]['size']);
            $this->assertSame(array_column($page['words'], 'text'), array_column($after[$index]['words'], 'text'), 'Text/page mismatch on page '.($index + 1));
            foreach ($page['words'] as $wordIndex => $word) {
                foreach ($word['box'] as $axis => $value) {
                    $maxDelta = max($maxDelta, abs($value - $after[$index]['words'][$wordIndex]['box'][$axis]));
                }
            }
        }
        $this->assertLessThanOrEqual(0.05, $maxDelta, 'QR processing must stay within 0.05 pt of the normalized Office render.');
        $this->assertPdfQr($fixed, 'DOKUMEN DRAFT - BELUM DISAHKAN. Bukan bukti pengesahan.');
        $this->assertSame($hash, hash_file('sha256', $source));
        File::copy($fixed, sys_get_temp_dir().'/simpel-layout-fixed.pdf');
    }

    public function test_private_document_matches_microsoft_word_reference(): void
    {
        $source = getenv('OFFICE_LAYOUT_FIXTURE');
        $reference = getenv('OFFICE_WORD_REFERENCE');
        if (! $source || ! $reference) {
            $this->markTestSkipped('Set OFFICE_LAYOUT_FIXTURE and OFFICE_WORD_REFERENCE for the independent Word fidelity acceptance gate.');
        }
        $this->assertFileExists($source);
        $this->assertFileExists($reference);
        $document = $this->document();
        $processed = app(DocxTemplateProcessor::class)->process($source, $document, false);
        $actual = $this->geometry(app(DocumentPdfService::class)->render($processed, $document, new DocumentVersion));
        $expected = $this->geometry($reference);
        $this->assertCount(count($expected), $actual, 'Word fidelity gate: Office output still changes the source pagination.');
        $maxDelta = 0;
        foreach ($expected as $index => $page) {
            foreach ($page['size'] as $axis => $size) {
                $this->assertEqualsWithDelta((float) $size, (float) $actual[$index]['size'][$axis], 0.2);
            }
            $this->assertSame(array_column($page['words'], 'text'), array_column($actual[$index]['words'], 'text'), 'Word fidelity gate: content moved on page '.($index + 1));
            foreach ($page['words'] as $wordIndex => $word) {
                foreach ($word['box'] as $axis => $value) {
                    $maxDelta = max($maxDelta, abs($value - $actual[$index]['words'][$wordIndex]['box'][$axis]));
                }
            }
        }
        $this->assertLessThanOrEqual(1.0, $maxDelta, 'Word fidelity gate: visible text shifted by more than 1 pt.');
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

    private function document(): Document
    {
        $document = new Document(['judul' => 'Uji layout', 'status' => 'draft']);
        $document->created_at = '2026-11-11 10:00:00';

        return $document->setRelation('signature', null)->setRelation('pengusul', new User(['name' => 'Pengusul']))->setRelation('unit', new Unit(['nama' => 'Unit']));
    }

    private function geometry(string $pdf): array
    {
        $dom = new \DOMDocument;
        $dom->loadXML('<doc>'.$this->boxes($pdf).'</doc>', LIBXML_NONET);
        $pages = [];
        foreach ($dom->getElementsByTagName('page') as $page) {
            $words = [];
            foreach ($page->getElementsByTagName('word') as $word) {
                $words[] = ['text' => $word->textContent, 'box' => array_map(fn ($key) => (float) $word->getAttribute($key), ['xMin', 'yMin', 'xMax', 'yMax'])];
            }
            $pages[] = ['size' => [$page->getAttribute('width'), $page->getAttribute('height')], 'words' => $words];
        }

        return $pages;
    }

    private function assertPdfQr(string $pdf, string $expected): int
    {
        $count = 0;
        foreach ($this->geometry($pdf) as $number => $page) {
            foreach ($page['words'] as $word) {
                if (! in_array($word['text'], ['${qr_code}', '${barcode_tte}'], true)) {
                    continue;
                }
                [$x1, $y1, $x2, $y2] = $word['box'];
                $scale = 200 / 72;
                $process = new Process(['pdftoppm', '-f', (string) ($number + 1), '-l', (string) ($number + 1), '-r', '200', '-png', '-singlefile', '-x', (string) max(0, (int) floor((($x1 + $x2) / 2 - 38) * $scale)), '-y', (string) max(0, (int) floor((($y1 + $y2) / 2 - 38) * $scale)), '-W', '212', '-H', '212', $pdf]);
                $process->setTimeout(15)->mustRun();
                $this->assertSame($expected, (string) (new QRCode)->readFromBlob($process->getOutput()), 'Rendered QR must scan on page '.($number + 1));
                $count++;
            }
        }
        $this->assertGreaterThan(0, $count);

        return $count;
    }
}
