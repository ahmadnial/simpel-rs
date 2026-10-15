<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\Unit;
use App\Models\User;
use App\Services\DocxTemplateProcessor;
use App\Services\WordDocumentNormalizer;
use chillerlan\QRCode\QRCode;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WordFixtures;
use Tests\TestCase;
use ZipArchive;

class DocxTemplateProcessorTest extends TestCase
{
    use WordFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function document(): Document
    {
        $document = new Document(['judul' => 'Kebijakan & Pelayanan', 'status' => 'draft']);
        $document->created_at = now();
        $document->setRelation('signature', null);
        $document->setRelation('pengusul', new User(['name' => 'Pengusul']));
        $document->setRelation('unit', new Unit(['nama' => 'Pelayanan']));

        return $document;
    }

    public function test_split_tokens_preserve_surrounding_text_fonts_and_page_geometry(): void
    {
        $file = $this->wordUpload('<w:p><w:pPr><w:spacing w:after="240"/></w:pPr><w:r><w:rPr><w:rFonts w:ascii="Arial"/><w:sz w:val="24"/></w:rPr><w:t>Awal ${ju</w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>dul} akhir ${unit_kerja}.</w:t></w:r></w:p>');
        $originalHash = hash_file('sha256', $file->getRealPath());
        $path = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $this->document());
        $xml = $this->part($path, 'word/document.xml');
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml));
        $this->assertSame('Awal Kebijakan & Pelayanan akhir Pelayanan.', $dom->textContent);
        $this->assertStringContainsString('w:w="18720"', $xml);
        $this->assertStringContainsString('w:orient="landscape"', $xml);
        $this->assertStringContainsString('w:ascii="Arial"', $xml);
        $this->assertStringContainsString('w:after="240"', $xml);
        $this->assertSame($originalHash, hash_file('sha256', $file->getRealPath()));
    }

    public function test_manual_date_is_not_marked_and_markers_never_enter_signed_document(): void
    {
        $file = $this->wordUpload('<w:p><w:r><w:t>15 Oktober 2026 </w:t></w:r><w:r><w:t>${tanggal_ttd}</w:t></w:r></w:p>');
        $document = $this->document();
        $processor = app(DocxTemplateProcessor::class);
        $marked = $processor->process($file->getRealPath(), $document);
        $xml = $this->part($marked, 'word/document.xml');
        $this->assertSame(1, substr_count($xml, '<w:hyperlink'));
        $this->assertStringNotContainsString('<w:bdr', $xml);
        $this->assertStringContainsString('<w:r><w:t>15 Oktober 2026 </w:t></w:r>', $xml);
        $clean = $processor->process($file->getRealPath(), $document, false);
        $this->assertStringNotContainsString('DC2626', $this->part($clean, 'word/document.xml'));
        $this->assertSame($marked, $processor->process($file->getRealPath(), $document));
        $signature = new DocumentSignature(['ditandatangani_at' => now(), 'qr_token' => 'test-token']);
        $signature->setRelation('penandatangan', new User(['name' => 'Direktur']));
        $document->setRelation('signature', $signature);
        $signed = $processor->process($file->getRealPath(), $document, true);
        $this->assertStringNotContainsString('DC2626', $this->part($signed, 'word/document.xml'));
        $this->assertStringNotContainsString('${tanggal_ttd}', $this->part($signed, 'word/document.xml'));
    }

    public function test_draft_qr_in_footer_has_its_own_relationship_and_png_content_type(): void
    {
        $file = $this->wordUpload(parts: ['word/footer1.xml' => '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>${qr_code}</w:t></w:r></w:p></w:ftr>']);
        $processor = app(DocxTemplateProcessor::class);
        $path = $processor->process($file->getRealPath(), $this->document());
        $xml = $this->part($path, 'word/footer1.xml');
        $this->assertStringContainsString('cx="825500" cy="825500"', $xml);
        $this->assertStringContainsString('rIdSimpelQr', $xml);
        $this->assertStringContainsString('media/simpel-qr.png', $this->part($path, 'word/_rels/footer1.xml.rels'));
        $this->assertStringContainsString('ContentType="image/png"', $this->part($path, '[Content_Types].xml'));
        $this->assertStringStartsWith("\x89PNG", $this->part($path, 'word/media/simpel-qr.png'));
        $this->assertTrue($processor->inspect($file->getRealPath())['has_qr']);
    }

    public function test_unknown_tokens_are_reported_and_tokens_do_not_cross_paragraphs(): void
    {
        $file = $this->wordUpload('<w:p><w:r><w:t>${tanggl_ttd} ${ju</w:t></w:r></w:p><w:p><w:r><w:t>dul}</w:t></w:r></w:p>');
        $inspection = app(DocxTemplateProcessor::class)->inspect($file->getRealPath());
        $this->assertSame(['tanggl_ttd'], $inspection['unknown']);
        $this->assertFalse($inspection['has_qr']);
    }

    public function test_draft_qr_is_red_and_scans_as_draft_while_signed_qr_keeps_its_verification_url(): void
    {
        $file = $this->wordUpload('<w:p><w:pPr><w:spacing w:line="360"/></w:pPr><w:r><w:t>${qr_code}</w:t></w:r></w:p>');
        $document = $this->document();
        $processor = app(DocxTemplateProcessor::class);
        foreach ([true, false] as $markers) {
            $draft = $processor->process($file->getRealPath(), $document, $markers);
            $png = $this->part($draft, 'word/media/simpel-qr.png');
            $this->assertSame('DOKUMEN DRAFT - BELUM DISAHKAN. Bukan bukti pengesahan.', (string) (new QRCode)->readFromBlob($png));
            $this->assertQrInkColor($png, 0xDC2626);
            $this->assertStringContainsString('cx="825500" cy="825500"', $this->part($draft, 'word/document.xml'));
            $this->assertStringContainsString('w:line="360"', $this->part($draft, 'word/document.xml'));
        }

        $signature = new DocumentSignature(['ditandatangani_at' => now(), 'qr_token' => 'test-qr-token']);
        $signature->setRelation('penandatangan', new User(['name' => 'Direktur']));
        $document->setRelation('signature', $signature);
        $signed = $processor->process($file->getRealPath(), $document);
        $png = $this->part($signed, 'word/media/simpel-qr.png');
        $this->assertSame(route('public.verify', 'test-qr-token'), (string) (new QRCode)->readFromBlob($png));
        $this->assertQrInkColor($png, 0x000000);
        $this->assertStringContainsString('cx="825500" cy="825500"', $this->part($signed, 'word/document.xml'));
    }

    private function assertQrInkColor(string $png, int $expected): void
    {
        $image = imagecreatefromstring($png);
        $colors = [];
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $colors[imagecolorat($image, $x, $y) & 0xFFFFFF] = true;
            }
        }
        $this->assertEqualsCanonicalizing([0xFFFFFF, $expected], array_keys($colors));
    }

    public function test_date_inside_existing_hyperlink_does_not_create_nested_links(): void
    {
        $file = $this->wordUpload('<w:p><w:hyperlink><w:r><w:t>Awal ${tanggal_draft} akhir</w:t></w:r></w:hyperlink></w:p>');
        $path = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $this->document());
        $dom = new \DOMDocument;
        $dom->loadXML($this->part($path, 'word/document.xml'));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $this->assertSame(0, $xpath->query('//w:hyperlink/w:hyperlink')->length);
        $this->assertStringStartsWith('Awal ', $dom->textContent);
        $this->assertStringEndsWith(' akhir', $dom->textContent);
    }

    public function test_strict_ooxml_namespace_and_nonstandard_prefix_are_preserved(): void
    {
        $file = $this->wordUpload(parts: ['word/document.xml' => '<x:document xmlns:x="http://purl.oclc.org/ooxml/wordprocessingml/main"><x:body><x:p><x:r><x:t>${judul}</x:t></x:r></x:p></x:body></x:document>']);
        app(WordDocumentNormalizer::class)->normalize($file);
        $path = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $this->document());
        $dom = new \DOMDocument;
        $dom->loadXML($this->part($path, 'word/document.xml'));
        $this->assertSame('http://purl.oclc.org/ooxml/wordprocessingml/main', $dom->documentElement->namespaceURI);
        $this->assertSame('Kebijakan & Pelayanan', $dom->textContent);
    }

    private function part(string $path, string $part): string
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $value = $zip->getFromName($part);
        $zip->close();

        return $value;
    }
}
