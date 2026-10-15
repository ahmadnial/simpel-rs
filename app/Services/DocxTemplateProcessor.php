<?php

namespace App\Services;

use App\Models\Document;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/** Edits text runs in a copy; page, paragraph, table and font properties remain intact. */
class DocxTemplateProcessor
{
    private const VARIABLES = ['nomor_surat', 'nomor_draft', 'tanggal_surat', 'tanggal_draft', 'tanggal_ttd', 'waktu_ttd', 'nama_penandatangan', 'jabatan_penandatangan', 'nama_pengusul', 'unit_kerja', 'judul', 'perihal', 'qr_code', 'barcode_tte'];

    private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public function process(string $source, Document $document, ?bool $markers = null): string
    {
        $signature = $document->signature;
        $markers = ! $signature && ($markers ?? true);
        $date = $document->created_at ?? now();
        $values = [
            'nomor_surat' => $document->nomor_surat,
            'nomor_draft' => '[DRAFT]',
            'tanggal_surat' => $document->tanggal_surat?->translatedFormat('d F Y'),
            'tanggal_draft' => $date->translatedFormat('d F Y'),
            'tanggal_ttd' => $signature?->ditandatangani_at?->translatedFormat('d F Y'),
            'waktu_ttd' => $signature?->ditandatangani_at?->translatedFormat('d F Y H:i').' WIB',
            'nama_penandatangan' => $signature?->penandatangan?->name,
            'jabatan_penandatangan' => $signature?->penandatangan?->jabatan,
            'nama_pengusul' => $document->pengusul?->name,
            'unit_kerja' => $document->unit?->nama,
            'judul' => $document->judul,
            'perihal' => $document->perihal ?? $document->judul,
        ];
        if (! $signature) {
            $values['waktu_ttd'] = null;
        }
        $qrValue = $signature ? route('public.verify', $signature->qr_token) : 'DOKUMEN DRAFT - BELUM DISAHKAN. Bukan bukti pengesahan.';
        $key = hash('sha256', 'template-v6|'.hash_file('sha256', $source).'|'.json_encode([$values, $qrValue, $markers], JSON_THROW_ON_ERROR));
        $directory = Storage::disk('local')->path('temp_processed');
        File::ensureDirectoryExists($directory);
        $target = $directory.'/'.$key.'.docx';
        if (is_file($target)) {
            return $target;
        }
        $temporary = $target.'.'.bin2hex(random_bytes(8)).'.tmp';
        File::copy($source, $temporary);
        $zip = new ZipArchive;
        if ($zip->open($temporary) !== true) {
            File::delete($temporary);
            abort(422, 'Salinan dokumen tidak dapat diproses. Unggah ulang berkas DOCX yang valid.');
        }
        try {
            $qrPng = null;
            $imageName = 'simpel-qr.png';
            while ($zip->locateName('word/media/'.$imageName) !== false) {
                $imageName = 'simpel-'.$imageName;
            }
            foreach ($this->parts($zip) as $part) {
                $dom = $this->xml($zip->getFromName($part));
                $xpath = new DOMXPath($dom);
                $namespace = $dom->documentElement->namespaceURI;
                $xpath->registerNamespace('w', $namespace);
                $relationship = null;
                foreach ($xpath->query('//w:p') as $paragraph) {
                    // Do not merge text across tabs, breaks, fields or nested text boxes.
                    $groups = $this->textGroups($xpath, $paragraph);
                    foreach ($groups as $nodes) {
                        $text = implode('', array_map(fn ($node) => $node->textContent, $nodes));
                        preg_match_all('/\$\{([^{}]+)\}/u', $text, $matches, PREG_OFFSET_CAPTURE);
                        foreach (array_reverse($matches[0], true) as $index => [$token, $offset]) {
                            $name = $matches[1][$index][0];
                            $isQr = in_array($name, ['qr_code', 'barcode_tte'], true);
                            if ($isQr && $relationship === null) {
                                $qrPng ??= $this->qrPng($qrValue, ! $signature);
                                $zip->addFromString('word/media/'.$imageName, $qrPng);
                                $relationship = $this->addImageRelationship($zip, $part, $imageName);
                            }
                            $isDate = str_starts_with($name, 'tanggal_') || $name === 'waktu_ttd';
                            $annotation = ! $signature && $isDate ? $this->addDateRelationship($zip, $part, $name) : null;
                            $this->replaceToken($nodes, $offset, strlen($token), $values[$name] ?? $token, $markers, $annotation, $isQr ? $relationship : null);
                        }
                    }
                }
                $zip->addFromString($part, $dom->saveXML());
            }
            if ($qrPng !== null) {
                $types = $this->xml($zip->getFromName('[Content_Types].xml'));
                $found = false;
                foreach ($types->getElementsByTagName('Default') as $type) {
                    $found = $found || strtolower($type->getAttribute('Extension')) === 'png';
                }
                if (! $found) {
                    $type = $types->createElementNS($types->documentElement->namespaceURI, 'Default');
                    $type->setAttribute('Extension', 'png');
                    $type->setAttribute('ContentType', 'image/png');
                    $types->documentElement->appendChild($type);
                    $zip->addFromString('[Content_Types].xml', $types->saveXML());
                }
            }
            $zip->close();
            if (! rename($temporary, $target)) {
                throw new \RuntimeException('Salinan dokumen gagal disimpan.');
            }
        } finally {
            if ($zip->status === ZipArchive::ER_OK && $zip->filename !== '') {
                $zip->close();
            }
            File::delete($temporary);
        }

        return $target;
    }

    public function inspect(string $source): array
    {
        $zip = new ZipArchive;
        abort_unless($zip->open($source) === true, 422, 'Dokumen tidak dapat dibaca.');
        $variables = [];
        try {
            foreach ($this->parts($zip) as $part) {
                $dom = $this->xml($zip->getFromName($part));
                $xpath = new DOMXPath($dom);
                $xpath->registerNamespace('w', $dom->documentElement->namespaceURI);
                foreach ($xpath->query('//w:p') as $paragraph) {
                    foreach ($this->textGroups($xpath, $paragraph) as $nodes) {
                        preg_match_all('/\$\{([^{}]+)\}/u', implode('', array_map(fn ($node) => $node->textContent, $nodes)), $matches);
                        $variables = array_merge($variables, $matches[1]);
                    }
                }
            }
        } finally {
            $zip->close();
        }

        return [
            'unknown' => array_values(array_diff(array_unique($variables), self::VARIABLES)),
            'has_qr' => count(array_intersect($variables, ['qr_code', 'barcode_tte'])) > 0,
        ];
    }

    private function textGroups(DOMXPath $xpath, DOMElement $paragraph): array
    {
        $groups = [[]];
        foreach ($xpath->query('.//w:t | .//w:tab | .//w:br | .//w:fldChar | .//w:instrText', $paragraph) as $node) {
            if ($xpath->query('ancestor::w:p[1]', $node)->item(0) !== $paragraph || $xpath->query('ancestor::w:del', $node)->length) {
                continue;
            }
            if ($node->localName !== 't') {
                $groups[] = [];
            } else {
                $groups[array_key_last($groups)][] = $node;
            }
        }

        return array_filter($groups);
    }

    private function replaceToken(array $nodes, int $offset, int $length, string $value, bool $markers, ?string $annotation, ?string $relationship): void
    {
        $position = 0;
        $first = null;
        foreach ($nodes as $node) {
            $text = $node->textContent;
            $end = $position + strlen($text);
            if ($end > $offset && $position < $offset + $length) {
                $start = max(0, $offset - $position);
                $stop = min(strlen($text), $offset + $length - $position);
                if ($first === null) {
                    $first = $node;
                    $prefix = substr($text, 0, $start);
                    $suffix = substr($text, $stop);
                    $node->textContent = $prefix;
                    $node->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                } else {
                    $node->textContent = substr($text, 0, $start).substr($text, $stop);
                    $node->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                }
            }
            $position = $end;
        }
        if (! $first || $first->parentNode->localName !== 'r') {
            return;
        }
        $dom = $first->ownerDocument;
        $run = $first->parentNode;
        $namespace = $run->namespaceURI;
        $replacement = $dom->createElementNS($namespace, 'w:r');
        $properties = null;
        foreach ($run->childNodes as $child) {
            if ($child->localName === 'rPr') {
                $properties = $child->cloneNode(true);
                $replacement->appendChild($properties);
                break;
            }
        }
        // Split the source run at the token so surrounding text keeps its original style.
        $tail = $replacement->cloneNode(true);
        if ($suffix !== '') {
            $tailText = $first->cloneNode(false);
            $tailText->textContent = $suffix;
            $tailText->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $tail->appendChild($tailText);
        }
        while ($first->nextSibling) {
            $tail->appendChild($first->nextSibling);
        }
        $run->parentNode->insertBefore($tail, $run->nextSibling);
        $run->parentNode->insertBefore($replacement, $tail);
        if ($relationship) {
            $drawing = $this->xml($this->qrDrawing($namespace, $relationship));
            $replacement->appendChild($dom->importNode($drawing->documentElement, true));
        } else {
            if ($markers) {
                $properties ??= $replacement->appendChild($dom->createElementNS($namespace, 'w:rPr'));
                foreach (iterator_to_array($properties->childNodes) as $child) {
                    if (in_array($child->localName, ['color', 'bdr'], true)) {
                        $properties->removeChild($child);
                    }
                }
                $color = $properties->appendChild($dom->createElementNS($namespace, 'w:color'));
                $color->setAttributeNS($namespace, 'w:val', 'DC2626');

            }
            $text = $replacement->appendChild($dom->createElementNS($namespace, 'w:t'));
            $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $text->textContent = $value;
            if ($annotation) {
                // Export a link rectangle as a PDF location marker. The viewer draws the
                // date box there; a Word text border would change line metrics.
                $link = $dom->createElementNS($namespace, 'w:hyperlink');
                $link->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', $annotation);
                if ($replacement->parentNode->localName === 'hyperlink') {
                    $originalLink = $replacement->parentNode;
                    $remainingLink = $originalLink->cloneNode(false);
                    while ($replacement->nextSibling) {
                        $remainingLink->appendChild($replacement->nextSibling);
                    }
                    $originalLink->parentNode->insertBefore($link, $originalLink->nextSibling);
                    $originalLink->parentNode->insertBefore($remainingLink, $link->nextSibling);
                } else {
                    $replacement->parentNode->insertBefore($link, $replacement);
                }
                $link->appendChild($replacement);
            }
        }
    }

    private function parts(ZipArchive $zip): array
    {
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#', $name)) {
                $parts[] = $name;
            }
        }

        return $parts;
    }

    private function xml(string $xml): DOMDocument
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadXML($xml, LIBXML_NONET) || $dom->doctype !== null) {
                abort(422, 'Struktur XML dokumen tidak dapat diproses. Buka dan simpan ulang di Word.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $dom;
    }

    private function addImageRelationship(ZipArchive $zip, string $part, string $imageName): string
    {
        $path = dirname($part).'/_rels/'.basename($part).'.rels';
        $dom = $this->xml($zip->getFromName($path) ?: '<Relationships xmlns="'.self::REL_NS.'"/>');
        $id = 'rIdSimpelQr';
        while (str_contains($dom->saveXML(), 'Id="'.$id.'"')) {
            $id .= 'X';
        }
        $relationship = $dom->createElementNS(self::REL_NS, 'Relationship');
        $relationship->setAttribute('Id', $id);
        $relationship->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image');
        $relationship->setAttribute('Target', 'media/'.$imageName);
        $dom->documentElement->appendChild($relationship);
        $zip->addFromString($path, $dom->saveXML());

        return $id;
    }

    private function addDateRelationship(ZipArchive $zip, string $part, string $name): string
    {
        $path = dirname($part).'/_rels/'.basename($part).'.rels';
        $dom = $this->xml($zip->getFromName($path) ?: '<Relationships xmlns="'.self::REL_NS.'"/>');
        $id = 'rIdSimpelDate'.bin2hex(random_bytes(8));
        $link = $dom->createElementNS(self::REL_NS, 'Relationship');
        $link->setAttribute('Id', $id);
        $link->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink');
        $link->setAttribute('Target', 'https://simpel-preview.invalid/variable/'.rawurlencode($name));
        $link->setAttribute('TargetMode', 'External');
        $dom->documentElement->appendChild($link);
        $zip->addFromString($path, $dom->saveXML());

        return $id;
    }

    private function qrPng(string $value, bool $draft): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'scale' => 5, 'addQuietzone' => true, 'outputBase64' => false,
            'moduleValues' => $draft ? array_map(
                fn (bool $dark) => $dark ? [220, 38, 38] : [255, 255, 255],
                QROutputInterface::DEFAULT_MODULE_VALUES,
            ) : [],
        ]);

        return (new QRCode($options))->render($value);
    }

    private function qrDrawing(string $namespace, string $relationship): string
    {
        // 65 pt square, identical for draft and final; no VML or undeclared prefixes.
        return '<w:drawing xmlns:w="'.$namespace.'" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="825500" cy="825500"/><wp:docPr id="'.random_int(100000, 2147483647).'" name="QR SIMPEL-RS"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="QR"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$relationship.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="825500" cy="825500"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>';
    }
}
