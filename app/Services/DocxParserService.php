<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use ZipArchive;
use DOMDocument;
use DOMXPath;

class DocxParserService
{
    /**
     * Ekstrak isi dokumen .docx menjadi HTML terformat menggunakan ZipArchive native PHP.
     */
    public function parseToHtml(string $filePath): string
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

        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!$xmlContent) {
            return '<div style="color:var(--text-muted); padding:2rem; text-align:center">Tidak dapat membaca struktur word/document.xml.</div>';
        }

        $dom = new DOMDocument();
        @$dom->loadXML($xmlContent);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $bodyNodes = $xpath->query('//w:body/*');
        $html = '';

        foreach ($bodyNodes as $node) {
            if ($node->nodeName === 'w:p') {
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

                if (trim($text) !== '') {
                    $style = "text-align: {$align}; margin-bottom: 0.8rem; line-height: 1.6;";
                    if ($isBold) $style .= " font-weight: bold;";
                    $html .= "<p style=\"{$style}\">" . nl2br($text) . "</p>";
                }
            } elseif ($node->nodeName === 'w:tbl') {
                // Render tabel sederhana
                $html .= '<table style="width:100%; border-collapse:collapse; margin:1rem 0" border="1" cellpadding="6">';
                $rows = $xpath->query('.//w:tr', $node);
                foreach ($rows as $row) {
                    $html .= '<tr>';
                    $cells = $xpath->query('.//w:tc', $row);
                    foreach ($cells as $cell) {
                        $cellText = '';
                        $cellTexts = $xpath->query('.//w:t', $cell);
                        foreach ($cellTexts as $ct) {
                            $cellText .= htmlspecialchars($ct->nodeValue);
                        }
                        $html .= '<td style="border:1px solid #ccc; padding:6px 10px">' . nl2br($cellText) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</table>';
            }
        }

        return $html ?: '<div style="color:var(--text-muted); padding:2rem; text-align:center">Naskah dinas tidak memiliki teks.</div>';
    }
}
