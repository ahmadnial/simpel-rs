<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use ZipArchive;
use DOMDocument;
use DOMXPath;

class DocxParserService
{
    /**
     * Ekstrak isi dokumen .docx menjadi HTML terformat beserta gambar Kop/Body (base64 Data URIs)
     * dan otomatis mengganti variabel template (${nomor_surat}, ${qr_code}, ${tanggal_ttd}, dll).
     */
    public function parseToHtml(string $filePath, ?Document $document = null): string
    {
        $realPath = null;

        if (Storage::disk('local')->exists($filePath)) {
            $realPath = Storage::disk('local')->path($filePath);
        } elseif (file_exists(storage_path('app/' . $filePath))) {
            $realPath = storage_path('app/' . $filePath);
        } elseif (file_exists(storage_path('app/private/' . $filePath))) {
            $realPath = storage_path('app/private/' . $filePath);
        } elseif (file_exists($filePath)) {
            $realPath = $filePath;
        }

        if (!$realPath || !file_exists($realPath)) {
            return '<div style="color:var(--text-muted); padding:2rem; text-align:center">Berkas dokumen belum diunggah atau tidak ditemukan di storage.</div>';
        }

        $zip = new ZipArchive();
        if ($zip->open($realPath) !== true) {
            return '<div style="color:var(--text-muted); padding:2rem; text-align:center">Format berkas bukan dokumen Word (.docx) yang valid.</div>';
        }

        // 1. Ekstrak gambar dari word/media/ dan buat peta rId -> base64 data URI
        $imageMap = $this->extractImagesAndRelationships($zip);

        // 2. Baca header XML (jika Kop Surat dalam header1.xml)
        $headerHtml = $this->parseXmlPart($zip, 'word/header1.xml', $imageMap);

        // 3. Baca body document.xml
        $bodyHtml = $this->parseXmlPart($zip, 'word/document.xml', $imageMap);

        $zip->close();

        $html = '';
        if (trim($headerHtml) !== '') {
            $html .= '<div class="docx-header-part" style="margin-bottom:1.5rem; padding-bottom:1rem; border-bottom:2px solid #1e293b">' . $headerHtml . '</div>';
        }
        $html .= $bodyHtml;

        // 4. Lakukan Variable Replacement jika konteks $document disedia kan
        if ($document) {
            $html = $this->replaceTemplateVariables($html, $document);
        }

        return $html ?: '<div style="color:var(--text-muted); padding:2rem; text-align:center">Naskah dinas tidak memiliki teks.</div>';
    }

    /**
     * Memproses file .docx (ZIP archive) untuk mengganti variabel template (${nomor_surat}, ${tanggal_ttd}, dll)
     * langsung di dalam file XML Word (word/document.xml, word/header1.xml, dll).
     */
    public function processDocxTemplate(string $rawPath, Document $document, ?bool $markers = null): string
    {
        return app(DocxTemplateProcessor::class)->process($rawPath, $document, $markers);
    }

    /**
     * Ganti variabel template ${...} dengan data aktual dokumen & Barcode QR Code TTE untuk HTML preview/PDF.
     */
    private function replaceTemplateVariables(string $html, Document $document): string
    {
        $signature = $document->signature;
        $penandatangan = $signature?->penandatangan
            ?? \App\Models\User::role('penandatangan')->first()
            ?? $document->pengusul;

        $nomorDraft = "[DRAFT - Belum Disahkan]";
        
        $nomorSurat = $document->nomor_surat ?? $nomorDraft;
        $tanggalDraft = $document->created_at ? $document->created_at->translatedFormat('d F Y') : now()->translatedFormat('d F Y');
        $tanggalSurat = $document->tanggal_surat ? $document->tanggal_surat->translatedFormat('d F Y') : $tanggalDraft;
        $tanggalTtd = $signature ? $signature->ditandatangani_at->translatedFormat('d F Y') : $tanggalDraft;
        $waktuTtd = $signature ? $signature->ditandatangani_at->translatedFormat('d F Y \j\a\m H:i') . ' WIB' : ($document->created_at ? $document->created_at->translatedFormat('d F Y \j\a\m H:i') . ' WIB' : now()->translatedFormat('d F Y \j\a\m H:i') . ' WIB');

        // Generate QR Code HTML/Img
        $qrHtml = '<div style="display:inline-block; padding:6px 12px; border:2px dashed #ef4444; border-radius:6px; background:#fef2f2; color:#dc2626; font-size:8.5pt; font-weight:700;">[ DRAFT - BELUM DISAHKAN ]</div>';

        if ($signature) {
            $verifyUrl = route('public.verify', $signature->qr_token);
            try {
                $options = new \chillerlan\QRCode\QROptions([
                    'outputInterface' => \chillerlan\QRCode\Output\QRGdImagePNG::class,
                    'scale' => 5,
                    'addQuietzone' => false,
                ]);
                $qrBase64 = (new \chillerlan\QRCode\QRCode($options))->render($verifyUrl);
                // Gunakan ukuran responsif max-width:100% dan height:auto agar tidak merusak format PDF
                $qrHtml = '<img src="' . $qrBase64 . '" style="max-width:100%; height:auto; width:85px; display:inline-block; margin:0 auto;" alt="Barcode TTE"/>';
            } catch (\Throwable $e) {
                // Fallback
                $qrHtml = '<div style="display:inline-block; padding:6px 10px; border:2px solid #16a34a; border-radius:6px; background:#f0fdf4; color:#16a34a; font-size:8pt; font-weight:bold;">[ PENGESAHAN INTERNAL - TOKEN: ' . substr($signature->qr_token, 0, 8) . ' ]</div>';
            }
        }

        $replacements = [
            '${nomor_surat}'          => htmlspecialchars($nomorSurat),
            '${nomor_draft}'          => htmlspecialchars($nomorDraft),
            '${tanggal_surat}'        => htmlspecialchars($tanggalSurat),
            '${tanggal_draft}'        => htmlspecialchars($tanggalDraft),
            '${tanggal_ttd}'          => htmlspecialchars($tanggalTtd),
            '${waktu_ttd}'            => htmlspecialchars($waktuTtd),
            '${nama_penandatangan}'   => htmlspecialchars($penandatangan?->name ?? 'Direktur Rumah Sakit'),
            '${jabatan_penandatangan}'=> htmlspecialchars($penandatangan?->jabatan ?? 'Direktur'),
            '${nama_pengusul}'        => htmlspecialchars($document->pengusul?->name ?? '-'),
            '${unit_kerja}'           => htmlspecialchars($document->unit?->nama ?? '-'),
            '${judul}'                => htmlspecialchars($document->judul ?? '-'),
            '${perihal}'              => htmlspecialchars($document->perihal ?? $document->judul),
            '${qr_code}'              => $qrHtml,
            '${barcode_tte}'          => $qrHtml,
        ];

        return strtr($html, $replacements);
    }

    /**
     * Ekstrak relasi rId -> gambar base64 dari _rels/document.xml.rels & media folder.
     */
    private function extractImagesAndRelationships(ZipArchive $zip): array
    {
        $imageMap = [];

        $relsFiles = ['word/_rels/document.xml.rels', 'word/_rels/header1.xml.rels'];

        foreach ($relsFiles as $relsPath) {
            $relsXml = $zip->getFromName($relsPath);
            if (!$relsXml) continue;

            $dom = new DOMDocument();
            @$dom->loadXML($relsXml);
            $relationships = $dom->getElementsByTagName('Relationship');

            foreach ($relationships as $rel) {
                $id = $rel->getAttribute('Id');
                $target = $rel->getAttribute('Target');

                if (str_contains($target, 'media/')) {
                    $mediaFileName = 'word/' . ltrim(str_replace('../', '', $target), '/');
                    $imageData = $zip->getFromName($mediaFileName);

                    if ($imageData) {
                        $extension = strtolower(pathinfo($mediaFileName, PATHINFO_EXTENSION));
                        $mime = match ($extension) {
                            'jpg', 'jpeg' => 'image/jpeg',
                            'gif'         => 'image/gif',
                            'svg'         => 'image/svg+xml',
                            default       => 'image/png',
                        };

                        $base64 = 'data:' . $mime . ';base64,' . base64_encode($imageData);
                        $imageMap[$id] = $base64;
                    }
                }
            }
        }

        return $imageMap;
    }

    /**
     * Parse XML part (document.xml atau header1.xml) menjadi HTML terformat.
     */
    private function parseXmlPart(ZipArchive $zip, string $xmlFileName, array $imageMap): string
    {
        $xmlContent = $zip->getFromName($xmlFileName);
        if (!$xmlContent) return '';

        $dom = new DOMDocument();
        @$dom->loadXML($xmlContent);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $xpath->registerNamespace('v', 'urn:schemas-microsoft-com:vml');
        $xpath->registerNamespace('o', 'urn:schemas-microsoft-com:office:office');

        $bodyNodes = $xpath->query('//w:body/* | //w:hdr/*');
        if ($bodyNodes->length === 0) return '';

        $html = '';
        $bodyListTracker = [];

        foreach ($bodyNodes as $node) {
            if ($node->nodeName === 'w:p') {
                $pContent = '';

                // Cek gambar di dalam paragraf (<w:drawing> atau <v:imagedata>)
                $drawings = $xpath->query('.//a:blip/@r:embed | .//v:imagedata/@r:id | .//v:imagedata/@o:relid', $node);
                foreach ($drawings as $blip) {
                    $rId = $blip->nodeValue;
                    if (isset($imageMap[$rId])) {
                        $pContent .= '<div style="text-align:center; margin:10px 0;"><img src="' . $imageMap[$rId] . '" style="max-width:100%; max-height:140px; display:inline-block;" alt="Logo/Gambar Kop"/></div>';
                    }
                }

                // Cek WordArt vector shape Kop Surat (<v:shape> dengan <v:textpath>)
                $wordArts = $xpath->query('.//v:shape', $node);
                foreach ($wordArts as $shape) {
                    $textPath = $xpath->query('.//v:textpath/@string', $shape)->item(0);
                    if ($textPath && trim($textPath->nodeValue) !== '') {
                        $fillColor = $shape->getAttribute('fillcolor') ?: '#00b050';
                        $waText = htmlspecialchars($textPath->nodeValue);
                        $pContent .= '<div style="display:inline-block; font-family:\'Times New Roman\', serif; font-weight:bold; font-size:1.8rem; color:' . $fillColor . '; border:2px solid ' . $fillColor . '; padding:2px 8px; border-radius:4px; margin-bottom:4px; text-align:center;">' . $waText . '</div>';
                    }
                }

                // Cek teks paragraf
                $text = '';
                $textNodes = $xpath->query('.//w:t', $node);
                foreach ($textNodes as $t) {
                    $text .= htmlspecialchars($t->nodeValue);
                }

                // Cek alignment
                $alignNode = $xpath->query('.//w:jc/@w:val', $node)->item(0);
                $align = $alignNode ? $alignNode->nodeValue : 'left';
                if ($align === 'both') $align = 'justify';

                // Cek bold/italic
                $isBold = $xpath->query('.//w:b', $node)->length > 0;

                // Cek Numbered List (<w:numPr>)
                $numPrNode = $xpath->query('.//w:pPr/w:numPr', $node)->item(0);
                $prefix = '';
                $indentStyle = '';
                if ($numPrNode) {
                    $ilvlNode = $xpath->query('.//w:ilvl/@w:val', $numPrNode)->item(0);
                    $numIdNode = $xpath->query('.//w:numId/@w:val', $numPrNode)->item(0);
                    $ilvl = $ilvlNode ? (int)$ilvlNode->nodeValue : 0;
                    $numId = $numIdNode ? (int)$numIdNode->nodeValue : 1;

                    if (!isset($bodyListTracker[$numId])) {
                        $bodyListTracker[$numId] = [0 => 0, 1 => 0, 2 => 0];
                    }

                    $bodyListTracker[$numId][$ilvl] = ($bodyListTracker[$numId][$ilvl] ?? 0) + 1;
                    for ($sub = $ilvl + 1; $sub <= 5; $sub++) {
                        $bodyListTracker[$numId][$sub] = 0;
                    }

                    if ($ilvl === 0) {
                        $prefix = $bodyListTracker[$numId][0] . '. ';
                    } elseif ($ilvl === 1) {
                        $subLetter = chr(96 + (($bodyListTracker[$numId][1] - 1) % 26 + 1));
                        $prefix = $subLetter . '. ';
                        $indentStyle = 'padding-left: 20px;';
                    } elseif ($ilvl === 2) {
                        $prefix = $bodyListTracker[$numId][2] . ') ';
                        $indentStyle = 'padding-left: 36px;';
                    }
                }

                if (trim($text) !== '' || $pContent !== '') {
                    $style = "text-align: {$align}; margin-bottom: 4px; line-height: 1.5; {$indentStyle}";
                    if ($isBold) $style .= " font-weight: bold;";
                    $html .= $pContent . ($text !== '' ? "<p style=\"{$style}\">" . $prefix . nl2br($text) . "</p>" : '');
                }
            } elseif ($node->nodeName === 'w:tbl') {
                // Parse Tabel dengan dukungan gambar di dalam cell, colspan, rowspan, vAlign, dan lebar persen
                $tblBorders = $xpath->query('.//w:tblPr/w:tblBorders/w:top/@w:val', $node)->item(0);
                $hasBorder = $tblBorders && !in_array($tblBorders->nodeValue, ['nil', 'none']);
                
                $tableStyle = 'width:100% !important; table-layout:fixed !important; border-collapse:collapse; margin:0.5rem 0; word-wrap:break-word;';
                $borderAttr = $hasBorder ? 'border="1"' : 'border="0"';
                $cellBorder = $hasBorder ? 'border:1px solid #334155;' : 'border:none;';
                
                $html .= '<table style="' . $tableStyle . '" ' . $borderAttr . ' cellpadding="6">';
                $rows = $xpath->query('.//w:tr', $node);
                
                // Pre-calculate rowspans for vMerge
                $vMergeMap = [];
                $rowIndex = 0;
                foreach ($rows as $row) {
                    $cells = $xpath->query('.//w:tc', $row);
                    $colIndex = 0;
                    foreach ($cells as $cell) {
                        $gridSpanNode = $xpath->query('.//w:tcPr/w:gridSpan/@w:val', $cell)->item(0);
                        $colspan = $gridSpanNode ? (int)$gridSpanNode->nodeValue : 1;
                        
                        $vMergeNode = $xpath->query('.//w:tcPr/w:vMerge', $cell)->item(0);
                        if ($vMergeNode) {
                            $vMergeVal = $vMergeNode->getAttribute('w:val');
                            if ($vMergeVal === 'restart') {
                                $vMergeMap[$rowIndex][$colIndex] = ['rowspan' => 1, 'active' => true];
                            } else {
                                for ($r = $rowIndex - 1; $r >= 0; $r--) {
                                    if (isset($vMergeMap[$r][$colIndex]) && $vMergeMap[$r][$colIndex]['active']) {
                                        $vMergeMap[$r][$colIndex]['rowspan']++;
                                        $vMergeMap[$rowIndex][$colIndex] = ['skip' => true, 'active' => false];
                                        break;
                                    }
                                }
                            }
                        }
                        $colIndex += $colspan;
                    }
                    $rowIndex++;
                }

                $rowIndex = 0;
                foreach ($rows as $row) {
                    $html .= '<tr>';
                    $cells = $xpath->query('.//w:tc', $row);
                    
                    // Hitung total dxa di dalam baris untuk kalkulasi lebar % presisi
                    $rowDxaTotal = 0;
                    foreach ($cells as $rc) {
                        $wN = $xpath->query('.//w:tcPr/w:tcW/@w:w', $rc)->item(0);
                        $rowDxaTotal += $wN ? (int)$wN->nodeValue : 2000;
                    }

                    $colIndex = 0;
                    foreach ($cells as $cell) {
                        $gridSpanNode = $xpath->query('.//w:tcPr/w:gridSpan/@w:val', $cell)->item(0);
                        $colspan = $gridSpanNode ? (int)$gridSpanNode->nodeValue : 1;
                        
                        if (isset($vMergeMap[$rowIndex][$colIndex]['skip']) && $vMergeMap[$rowIndex][$colIndex]['skip']) {
                            $colIndex += $colspan;
                            continue;
                        }

                        $rowspan = isset($vMergeMap[$rowIndex][$colIndex]['rowspan']) ? $vMergeMap[$rowIndex][$colIndex]['rowspan'] : 1;
                        
                        // Vertical Alignment (w:vAlign)
                        $vAlignNode = $xpath->query('.//w:tcPr/w:vAlign/@w:val', $cell)->item(0);
                        $vAlign = 'top';
                        if ($vAlignNode) {
                            $vVal = $vAlignNode->nodeValue;
                            if ($vVal === 'center') $vAlign = 'middle';
                            elseif ($vVal === 'bottom') $vAlign = 'bottom';
                        }

                        $tcWNode = $xpath->query('.//w:tcPr/w:tcW/@w:w', $cell)->item(0);
                        $cellStyle = $cellBorder . " padding:6px 8px; vertical-align:{$vAlign}; box-sizing:border-box; word-wrap:break-word; overflow-wrap:break-word;";
                        if ($tcWNode && $rowDxaTotal > 0) {
                            $dxa = (int)$tcWNode->nodeValue;
                            $pct = round(($dxa / $rowDxaTotal) * 100, 1);
                            $cellStyle .= " width:{$pct}%;";
                        }
                        
                        $attrs = '';
                        if ($colspan > 1) $attrs .= ' colspan="' . $colspan . '"';
                        if ($rowspan > 1) $attrs .= ' rowspan="' . $rowspan . '"';
                        
                        // Isi cell
                        $cellImg = '';
                        $drawings = $xpath->query('.//a:blip/@r:embed | .//v:imagedata/@r:id | .//v:imagedata/@o:relid', $cell);
                        foreach ($drawings as $blip) {
                            $rId = $blip->nodeValue;
                            if (isset($imageMap[$rId])) {
                                $cellImg .= '<img src="' . $imageMap[$rId] . '" style="max-width:100%; height:auto; width:85px; display:block; margin:0 auto 4px;" alt="Logo/Barcode"/>';
                            }
                        }

                        // Cek WordArt vector shape Kop Surat (<v:shape> dengan <v:textpath>)
                        $wordArts = $xpath->query('.//v:shape', $cell);
                        foreach ($wordArts as $shape) {
                            $textPath = $xpath->query('.//v:textpath/@string', $shape)->item(0);
                            if ($textPath && trim($textPath->nodeValue) !== '') {
                                $fillColor = $shape->getAttribute('fillcolor') ?: '#00b050';
                                $waText = htmlspecialchars($textPath->nodeValue);
                                $cellImg .= '<div style="display:inline-block; font-family:\'Times New Roman\', serif; font-weight:bold; font-size:1.6rem; color:' . $fillColor . '; border:2px solid ' . $fillColor . '; padding:2px 8px; border-radius:4px; margin:0 auto 4px; text-align:center;">' . $waText . '</div>';
                            }
                        }

                        $cellText = '';
                        $paras = $xpath->query('.//w:p', $cell);
                        $cellListTracker = [];

                        foreach ($paras as $p) {
                            $pText = '';
                            $runs = $xpath->query('.//w:t', $p);
                            foreach ($runs as $run) {
                                $pText .= htmlspecialchars($run->nodeValue);
                            }
                            
                            if ($xpath->query('.//w:b', $p)->length > 0) {
                                $pText = "<strong>$pText</strong>";
                            }

                            // Alignment (jc) per paragraph di dalam cell
                            $pJcNode = $xpath->query('.//w:pPr/w:jc/@w:val', $p)->item(0);
                            $pAlignStyle = '';
                            if ($pJcNode) {
                                $pa = $pJcNode->nodeValue;
                                if ($pa === 'both') $pa = 'justify';
                                $pAlignStyle = "text-align: {$pa};";
                            }

                            // Cek Numbered List di dalam cell (<w:numPr>)
                            $numPrNode = $xpath->query('.//w:pPr/w:numPr', $p)->item(0);
                            $prefix = '';
                            $indentStyle = '';
                            if ($numPrNode) {
                                $ilvlNode = $xpath->query('.//w:ilvl/@w:val', $numPrNode)->item(0);
                                $numIdNode = $xpath->query('.//w:numId/@w:val', $numPrNode)->item(0);
                                $ilvl = $ilvlNode ? (int)$ilvlNode->nodeValue : 0;
                                $numId = $numIdNode ? (int)$numIdNode->nodeValue : 1;

                                if (!isset($cellListTracker[$numId])) {
                                    $cellListTracker[$numId] = [0 => 0, 1 => 0, 2 => 0];
                                }

                                $cellListTracker[$numId][$ilvl] = ($cellListTracker[$numId][$ilvl] ?? 0) + 1;
                                for ($sub = $ilvl + 1; $sub <= 5; $sub++) {
                                    $cellListTracker[$numId][$sub] = 0;
                                }

                                if ($ilvl === 0) {
                                    $prefix = $cellListTracker[$numId][0] . '. ';
                                } elseif ($ilvl === 1) {
                                    $subLetter = chr(96 + (($cellListTracker[$numId][1] - 1) % 26 + 1));
                                    $prefix = $subLetter . '. ';
                                    $indentStyle = 'padding-left: 18px;';
                                } elseif ($ilvl === 2) {
                                    $prefix = $cellListTracker[$numId][2] . ') ';
                                    $indentStyle = 'padding-left: 32px;';
                                }
                            }

                            if (trim($pText) !== '' || $prefix !== '') {
                                $cellText .= "<div style=\"margin-bottom:4px; font-size:10pt; line-height:1.45; {$indentStyle} {$pAlignStyle}\">" . $prefix . nl2br($pText) . "</div>";
                            }
                        }

                        $html .= '<td style="' . $cellStyle . '"' . $attrs . '>' . $cellImg . $cellText . '</td>';
                        
                        $colIndex += $colspan;
                    }
                    $html .= '</tr>';
                    $rowIndex++;
                }
                $html .= '</table>';
            }
        }

        return $html;
    }
}
