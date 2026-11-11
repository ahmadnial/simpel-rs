<?php

namespace Tests\Unit;

use App\Exceptions\DocumentConversionException;
use App\Models\Document;
use App\Services\DocxTemplateProcessor;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WordFixtures;
use Tests\TestCase;
use ZipArchive;

class DocxStyleCompatibilityTest extends TestCase
{
    use WordFixtures;

    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_multilevel_inheritance_merges_spacing_attributes_and_preserves_overrides(): void
    {
        $styles = '<w:styles xmlns:w="'.self::W.'"><w:style w:type="table" w:styleId="Base"><w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr></w:style>'
            .'<w:style w:type="table" w:styleId="Middle"><w:basedOn w:val="Base"/><w:pPr><w:spacing w:line="360"/></w:pPr><w:rPr><w:sz w:val="24"/></w:rPr></w:style>'
            .'<w:style w:type="table" w:styleId="Child"><w:basedOn w:val="Middle"/><w:pPr><w:spacing w:before="40"/></w:pPr><w:rPr><w:szCs w:val="28"/></w:rPr><w:tblPr><w:tblW w:w="5000" w:type="pct"/></w:tblPr></w:style></w:styles>';
        $body = '<w:p><w:r><w:rPr><w:sz w:val="32"/></w:rPr><w:t>Override run tetap</w:t></w:r></w:p>';
        $file = $this->wordUpload($body, parts: ['word/styles.xml' => $styles]);
        $hash = hash_file('sha256', $file->getRealPath());
        $path = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $this->document());
        $xpath = $this->styles($path);
        $base = '/w:styles/w:style[@w:styleId="Child"]';
        foreach (['sz' => '24', 'szCs' => '28'] as $name => $value) {
            $this->assertSame($value, $xpath->evaluate('string('.$base.'/w:rPr/w:'.$name.'/@w:val)'));
        }
        foreach (['before' => '40', 'after' => '0', 'line' => '360', 'lineRule' => 'auto'] as $name => $value) {
            $this->assertSame($value, $xpath->evaluate('string('.$base.'/w:pPr/w:spacing/@w:'.$name.')'));
        }
        $this->assertSame('5000', $xpath->evaluate('string('.$base.'/w:tblPr/w:tblW/@w:w)'));
        $this->assertStringContainsString('w:val="32"', $this->part($path, 'word/document.xml'));
        $this->assertSame($hash, hash_file('sha256', $file->getRealPath()));
    }

    public function test_conditional_regions_keep_their_own_override_and_inherit_missing_properties(): void
    {
        $file = $this->wordUpload(parts: ['word/styles.xml' => '<w:styles xmlns:w="'.self::W.'"><w:style w:type="table" w:styleId="Base"><w:rPr><w:sz w:val="20"/></w:rPr><w:tblStylePr w:type="firstRow"><w:rPr><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr></w:tblStylePr><w:tblStylePr w:type="lastRow"><w:tcPr><w:shd w:fill="EEEEEE"/></w:tcPr></w:tblStylePr></w:style>'
            .'<w:style w:type="table" w:styleId="Child"><w:basedOn w:val="Base"/><w:rPr><w:sz w:val="24"/></w:rPr><w:tblStylePr w:type="firstRow"><w:rPr><w:sz w:val="32"/></w:rPr></w:tblStylePr></w:style></w:styles>']);
        $path = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $this->document());
        $xpath = $this->styles($path);
        $base = '/w:styles/w:style[@w:styleId="Child"]';
        $this->assertSame('24', $xpath->evaluate('string('.$base.'/w:rPr/w:sz/@w:val)'));
        $this->assertSame('32', $xpath->evaluate('string('.$base.'/w:tblStylePr[@w:type="firstRow"]/w:rPr/w:sz/@w:val)'));
        $this->assertSame('28', $xpath->evaluate('string('.$base.'/w:tblStylePr[@w:type="firstRow"]/w:rPr/w:szCs/@w:val)'));
        $this->assertSame(0, $xpath->query($base.'/w:tblStylePr[@w:type="lastRow"]')->length, 'Do not introduce empty conditional overrides.');
    }

    public function test_missing_parent_does_not_invent_properties_or_rewrite_unchanged_styles(): void
    {
        $styles = '<w:styles xmlns:w="'.self::W.'"><w:style w:type="table" w:styleId="Child"><w:basedOn w:val="Missing"/><w:tblPr><w:tblW w:w="3000" w:type="dxa"/></w:tblPr></w:style></w:styles>';
        $file = $this->wordUpload(parts: ['word/styles.xml' => $styles]);
        $path = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $this->document());
        $this->assertSame($styles, $this->part($path, 'word/styles.xml'));
    }

    public function test_legacy_editable_docx_styles_are_unchanged(): void
    {
        $styles = '<w:styles xmlns:w="'.self::W.'"><w:style w:type="table" w:styleId="Base"><w:rPr><w:sz w:val="20"/></w:rPr></w:style><w:style w:type="table" w:styleId="Child"><w:basedOn w:val="Base"/></w:style></w:styles>';
        $file = $this->wordUpload(parts: ['word/styles.xml' => $styles]);
        $path = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $this->document(), pdfOverlay: false);
        $this->assertSame($styles, $this->part($path, 'word/styles.xml'));
    }

    public function test_cyclic_table_style_is_rejected_without_publishing_a_partial_cache(): void
    {
        $file = $this->wordUpload(parts: ['word/styles.xml' => '<w:styles xmlns:w="'.self::W.'"><w:style w:type="table" w:styleId="A"><w:basedOn w:val="B"/></w:style><w:style w:type="table" w:styleId="B"><w:basedOn w:val="A"/></w:style></w:styles>']);
        try {
            app(DocxTemplateProcessor::class)->process($file->getRealPath(), $this->document());
            $this->fail('Cyclic inheritance must not recurse indefinitely.');
        } catch (DocumentConversionException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame([], Storage::disk('local')->allFiles('temp_processed'));
        }
    }

    public function test_strict_ooxml_namespace_with_a_different_prefix_is_supported(): void
    {
        $namespace = 'http://purl.oclc.org/ooxml/wordprocessingml/main';
        $file = $this->wordUpload(parts: ['word/styles.xml' => '<x:styles xmlns:x="'.$namespace.'"><x:style x:type="table" x:styleId="Base"><x:rPr><x:sz x:val="20"/></x:rPr></x:style><x:style x:type="table" x:styleId="Child"><x:basedOn x:val="Base"/></x:style></x:styles>']);
        $path = app(DocxTemplateProcessor::class)->process($file->getRealPath(), $this->document());
        $xpath = $this->styles($path, $namespace);
        $this->assertSame('20', $xpath->evaluate('string(/w:styles/w:style[@w:styleId="Child"]/w:rPr/w:sz/@w:val)'));
    }

    private function document(): Document
    {
        return (new Document(['judul' => 'QC style']))->setRelation('signature', null)->setRelation('pengusul', null)->setRelation('unit', null);
    }

    private function part(string $path, string $name): string
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName($name);
        $zip->close();

        return $xml;
    }

    private function styles(string $path, string $namespace = self::W): DOMXPath
    {
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($this->part($path, 'word/styles.xml'), LIBXML_NONET));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', $namespace);

        return $xpath;
    }
}
