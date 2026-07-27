<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\DocxParserService;
use App\Services\DocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class DocumentController extends Controller
{
    protected DocumentService $documentService;

    public function __construct(DocumentService $documentService)
    {
        $this->documentService = $documentService;
    }

    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Document::where('pengusul_id', $user->id)
            ->with(['documentType', 'unit', 'currentVersion']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('judul', 'like', '%' . $request->search . '%');
        }

        $documents = $query->latest()->paginate(10);

        return view('dokumen.index', compact('documents'));
    }

    public function create()
    {
        $documentTypes = DocumentType::active()->get();
        $verifikators = User::permission('dokumen.verifikasi')
            ->where('is_active', true)
            ->where('id', '!=', auth()->id())
            ->get();

        return view('dokumen.create', compact('documentTypes', 'verifikators'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'judul'             => 'required|string|max:255',
            'document_type_id'  => 'required|exists:document_types,id',
            'perihal'           => 'nullable|string|max:255',
            'keterangan'        => 'nullable|string',
            'file_dokumen'      => 'required|file|mimes:docx,doc,pdf|max:10240',
            'verifikator_id'    => 'nullable|exists:users,id',
            'is_rahasia'        => 'nullable|boolean',
        ]);

        $document = $this->documentService->uploadDraft(
            [
                'judul'            => $validated['judul'],
                'document_type_id' => $validated['document_type_id'],
                'unit_id'          => auth()->user()->unit_id,
                'perihal'          => $validated['perihal'] ?? null,
                'keterangan'       => $validated['keterangan'] ?? null,
                'is_rahasia'       => $request->boolean('is_rahasia'),
            ],
            $request->file('file_dokumen')
        );

        if ($request->filled('verifikator_id')) {
            $this->documentService->ajukanDokumen($document, $request->verifikator_id);
            return redirect()->route('dokumen.show', $document)->with('success', 'Dokumen berhasil dibuat dan diajukan ke verifikator.');
        }

        return redirect()->route('dokumen.show', $document)->with('success', 'Draft dokumen berhasil dibuat.');
    }

    public function show(Document $document)
    {
        $document->load([
            'documentType', 'unit', 'pengusul',
            'versions.uploader', 'verifications.verifikator',
            'signature.penandatangan', 'auditLogs'
        ]);

        $verifikators = User::permission('dokumen.verifikasi')
            ->where('is_active', true)
            ->where('id', '!=', auth()->id())
            ->get();

        return view('dokumen.show', compact('document', 'verifikators'));
    }

    public function edit(Document $document)
    {
        return redirect()->route('onlyoffice.editor', $document);
    }

    public function updateEditor(Request $request, Document $document)
    {
        Gate::authorize('update', $document);

        $request->validate([
            'content' => 'required|string',
            'catatan' => 'nullable|string|max:255',
        ]);

        $htmlContent = $request->input('content');
        $catatan = $request->input('catatan', 'Disunting via Web Editor SIMPEL-RS');

        // Convert HTML content to a minimal .docx file using ZipArchive
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir . '/' . uniqid('editor_') . '.docx';

        $this->createDocxFromHtml($htmlContent, $tempFile);

        // Simpan sebagai versi baru menggunakan UploadedFile
        $uploadedFile = new \Illuminate\Http\UploadedFile(
            $tempFile,
            $document->judul . '.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true // test mode agar tidak validasi is_uploaded_file()
        );

        $this->documentService->simpanVersi($document, $uploadedFile, $catatan);

        // Hapus temp file
        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }

        return redirect()->route('dokumen.show', $document)->with('success', 'Perubahan naskah dinas berhasil disimpan sebagai versi baru.');
    }

    /**
     * Buat file .docx minimal dari konten HTML.
     */
    private function createDocxFromHtml(string $html, string $outputPath): void
    {
        // Strip tags yang tidak diperlukan, bersihkan HTML
        $cleanHtml = strip_tags($html, '<p><br><b><strong><i><em><u><h1><h2><h3><h4><h5><h6><ul><ol><li><table><tr><td><th><span><div><hr>');

        // Convert HTML ke WordprocessingML paragraf
        $bodyXml = $this->htmlToWordXml($cleanHtml);

        $zip = new \ZipArchive();
        $zip->open($outputPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        // [Content_Types].xml
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>');

        // _rels/.rels
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>');

        // word/_rels/document.xml.rels
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
</Relationships>');

        // word/document.xml
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas"
            xmlns:mo="http://schemas.microsoft.com/office/mac/office/2008/main"
            xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"
            xmlns:mv="urn:schemas-microsoft-com:mac:vml"
            xmlns:o="urn:schemas-microsoft-com:office:office"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
            xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"
            xmlns:v="urn:schemas-microsoft-com:vml"
            xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
            xmlns:w10="urn:schemas-microsoft-com:office:word"
            xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml">
  <w:body>' . $bodyXml . '
    <w:sectPr>
      <w:pgSz w:w="11906" w:h="16838"/>
      <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>
    </w:sectPr>
  </w:body>
</w:document>');

        $zip->close();
    }

    /**
     * Convert HTML sederhana ke WordprocessingML XML.
     */
    private function htmlToWordXml(string $html): string
    {
        $xml = '';

        // Normalisasi line breaks
        $html = str_replace(["\r\n", "\r"], "\n", $html);

        // Parse HTML
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            // Fallback: buat paragraf dari plain text
            $text = strip_tags($html);
            return '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($text) . '</w:t></w:r></w:p>';
        }

        foreach ($body->childNodes as $node) {
            $xml .= $this->nodeToWordXml($node);
        }

        return $xml;
    }

    /**
     * Convert satu DOM node ke WordprocessingML XML.
     */
    private function nodeToWordXml(\DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = $node->nodeValue;
            if (trim($text) === '') return '';
            return '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($text) . '</w:t></w:r></w:p>';
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) return '';

        $tag = strtolower($node->nodeName);
        $xml = '';

        switch ($tag) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
                $level = substr($tag, 1);
                $text = $node->textContent;
                $xml .= '<w:p>';
                $xml .= '<w:pPr><w:pStyle w:val="Heading' . $level . '"/></w:pPr>';
                $xml .= '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">' . htmlspecialchars($text) . '</w:t></w:r>';
                $xml .= '</w:p>';
                break;

            case 'p':
            case 'div':
                $xml .= '<w:p>';
                $xml .= $this->inlineNodesToRuns($node);
                $xml .= '</w:p>';
                break;

            case 'br':
                $xml .= '<w:p></w:p>';
                break;

            case 'ul':
            case 'ol':
                foreach ($node->childNodes as $li) {
                    if ($li->nodeType === XML_ELEMENT_NODE && strtolower($li->nodeName) === 'li') {
                        $xml .= '<w:p>';
                        $xml .= '<w:r><w:t xml:space="preserve">• ' . htmlspecialchars($li->textContent) . '</w:t></w:r>';
                        $xml .= '</w:p>';
                    }
                }
                break;

            case 'table':
                $xml .= '<w:tbl><w:tblPr><w:tblBorders>';
                $xml .= '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
                $xml .= '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
                $xml .= '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
                $xml .= '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
                $xml .= '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
                $xml .= '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>';
                $xml .= '</w:tblBorders></w:tblPr>';

                foreach ($node->childNodes as $child) {
                    if ($child->nodeType === XML_ELEMENT_NODE) {
                        $rows = ($child->nodeName === 'tr') ? [$child] : iterator_to_array($child->childNodes);
                        foreach ($rows as $row) {
                            if ($row->nodeType === XML_ELEMENT_NODE && strtolower($row->nodeName) === 'tr') {
                                $xml .= '<w:tr>';
                                foreach ($row->childNodes as $cell) {
                                    if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['td', 'th'])) {
                                        $xml .= '<w:tc><w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($cell->textContent) . '</w:t></w:r></w:p></w:tc>';
                                    }
                                }
                                $xml .= '</w:tr>';
                            }
                        }
                    }
                }
                $xml .= '</w:tbl>';
                break;

            case 'hr':
                $xml .= '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="auto"/></w:pBdr></w:pPr></w:p>';
                break;

            default:
                // Untuk tag lain, proses children
                foreach ($node->childNodes as $child) {
                    $xml .= $this->nodeToWordXml($child);
                }
                break;
        }

        return $xml;
    }

    /**
     * Convert inline child nodes ke Word runs (w:r) dengan formatting.
     */
    private function inlineNodesToRuns(\DOMNode $parent): string
    {
        $runs = '';
        foreach ($parent->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = $child->nodeValue;
                if ($text !== '') {
                    $runs .= '<w:r><w:t xml:space="preserve">' . htmlspecialchars($text) . '</w:t></w:r>';
                }
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($child->nodeName);
                $rPr = '';

                if (in_array($tag, ['b', 'strong'])) $rPr .= '<w:b/>';
                if (in_array($tag, ['i', 'em'])) $rPr .= '<w:i/>';
                if ($tag === 'u') $rPr .= '<w:u w:val="single"/>';

                if ($rPr) {
                    $runs .= '<w:r><w:rPr>' . $rPr . '</w:rPr><w:t xml:space="preserve">' . htmlspecialchars($child->textContent) . '</w:t></w:r>';
                } elseif ($tag === 'br') {
                    $runs .= '<w:r><w:br/></w:r>';
                } else {
                    $runs .= '<w:r><w:t xml:space="preserve">' . htmlspecialchars($child->textContent) . '</w:t></w:r>';
                }
            }
        }
        return $runs;
    }

    public function preview(Document $document, $versionId = null)
    {
        $version = $versionId ? $document->versions()->find($versionId) : $document->currentVersion;
        if (!$version) {
            $version = $document->versions()->first();
        }
        if (!$version) {
            abort(404, 'File versi tidak ditemukan.');
        }

        $path = $this->documentService->ensureDocxFileExists($document, $version);
        $processedPath = (new \App\Services\DocxParserService())->processDocxTemplate($path, $document);

        return response()->file($processedPath, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'inline; filename="' . $version->file_name . '"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public function previewPdf(Document $document, $versionId = null)
    {
        $version = $versionId ? $document->versions()->find($versionId) : $document->currentVersion;
        if (!$version) {
            $version = $document->versions()->first();
        }
        if (!$version) {
            abort(404, 'File versi tidak ditemukan.');
        }

        $path = $this->documentService->ensureDocxFileExists($document, $version);
        $processedDocxPath = (new \App\Services\DocxParserService())->processDocxTemplate($path, $document);
        $pdfPath = $this->convertDocxToPdf($processedDocxPath, $document, $version);

        return response()->file($pdfPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . pathinfo($version->file_name, PATHINFO_FILENAME) . '.pdf"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function convertDocxToPdf(string $docxPath, Document $document, $version): string
    {
        $cacheDir = storage_path('app/private/pdf_cache');
        if (!file_exists($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $hash = md5_file($docxPath) . '_' . ($document->updated_at ? $document->updated_at->timestamp : 0) . '_' . ($document->signature ? $document->signature->id : 0);
        $pdfFilename = 'pdf_' . $document->id . '_' . $version->id . '_' . $hash . '.pdf';
        $pdfPath = $cacheDir . '/' . $pdfFilename;

        if (file_exists($pdfPath) && filesize($pdfPath) > 0) {
            return $pdfPath;
        }

        // Cek 1: Jika Gotenberg Docker API service tersedia di server (0.2s ultra fast server-side)
        try {
            $gotenbergUrl = env('GOTENBERG_URL', 'http://localhost:3000');
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->attach('files', file_get_contents($docxPath), basename($docxPath))
                ->post($gotenbergUrl . '/forms/libreoffice/convert');

            if ($response->successful() && strlen($response->body()) > 500) {
                file_put_contents($pdfPath, $response->body());
                return $pdfPath;
            }
        } catch (\Throwable $e) {}

        // Cek 2: Jika soffice (LibreOffice CLI) tersedia di server
        $sofficeBin = env('LIBREOFFICE_PATH');
        if (!$sofficeBin || !file_exists($sofficeBin)) {
            if (file_exists('/Applications/LibreOffice.app/Contents/MacOS/soffice')) {
                $sofficeBin = '/Applications/LibreOffice.app/Contents/MacOS/soffice';
            } elseif (file_exists('/usr/bin/soffice')) {
                $sofficeBin = '/usr/bin/soffice';
            } elseif (file_exists('/usr/bin/libreoffice')) {
                $sofficeBin = '/usr/bin/libreoffice';
            } else {
                $which = trim(exec('which soffice 2>/dev/null'));
                if ($which && file_exists($which)) {
                    $sofficeBin = $which;
                }
            }
        }

        if ($sofficeBin) {
            $tempDir = storage_path('app/private/temp_pdf_' . uniqid());
            @mkdir($tempDir, 0755, true);

            $cmd = escapeshellcmd($sofficeBin) . ' --headless --convert-to pdf --outdir ' . escapeshellarg($tempDir) . ' ' . escapeshellarg($docxPath) . ' 2>&1';
            exec($cmd);

            $generatedPdf = $tempDir . '/' . pathinfo($docxPath, PATHINFO_FILENAME) . '.pdf';
            if (file_exists($generatedPdf) && filesize($generatedPdf) > 0) {
                @copy($generatedPdf, $pdfPath);
                @unlink($generatedPdf);
                @rmdir($tempDir);
                return $pdfPath;
            }
            @rmdir($tempDir);
        }

        // Fallback: Menggunakan DomPDF via DocxParserService
        $parser = new \App\Services\DocxParserService();
        $bodyHtml = $parser->parseToHtml($docxPath, $document);

        $signature = $document->signature;
        $penandatanganUser = $signature?->penandatangan
            ?? User::role('penandatangan')->first()
            ?? User::whereHas('roles', fn($q) => $q->where('name', 'like', '%direktur%'))->first()
            ?? $document->pengusul;

        $qrCodeBase64 = null;
        if ($signature) {
            $verifyUrl = route('public.verify', $signature->qr_token);
            try {
                $options = new \chillerlan\QRCode\QROptions([
                    'outputInterface' => \chillerlan\QRCode\Output\QRGdImagePNG::class,
                    'scale' => 5,
                    'addQuietzone' => false,
                ]);
                $qrCodeBase64 = (new \chillerlan\QRCode\QRCode($options))->render($verifyUrl);
            } catch (\Throwable $e) {}
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.naskah', [
            'document'          => $document,
            'version'           => $version,
            'bodyHtml'          => $bodyHtml,
            'signature'         => $signature,
            'penandatanganUser' => $penandatanganUser,
            'qrCodeBase64'      => $qrCodeBase64,
        ])->setPaper('A4', 'portrait')
          ->setOption('isRemoteEnabled', true)
          ->setOption('isHtml5ParserEnabled', true);

        $pdf->save($pdfPath);
        return $pdfPath;
    }

    public function uploadVersi(Request $request, Document $document)
    {
        Gate::authorize('update', $document);

        $request->validate([
            'file_dokumen' => 'required|file|mimes:docx,doc,pdf|max:10240',
            'catatan'      => 'nullable|string|max:500',
        ]);

        $this->documentService->simpanVersi(
            $document,
            $request->file('file_dokumen'),
            $request->catatan
        );

        return back()->with('success', 'Versi baru dokumen berhasil diunggah.');
    }

    public function ajukan(Request $request, Document $document)
    {
        $request->validate([
            'verifikator_id' => 'required|exists:users,id',
        ]);

        $this->documentService->ajukanDokumen($document, $request->verifikator_id);

        return back()->with('success', 'Dokumen berhasil diajukan ke verifikator.');
    }

    public function download(Document $document, $versionId = null)
    {
        return $this->downloadPdf($document, $versionId);
    }

    public function downloadPdf(Document $document, $versionId = null)
    {
        $version = $versionId ? $document->versions()->find($versionId) : $document->currentVersion;
        $version = $version ?? $document->currentVersion ?? $document->versions()->first();

        if (!$version) {
            abort(444, 'File dokumen tidak ditemukan.');
        }

        $path = $this->documentService->ensureDocxFileExists($document, $version);

        // Process DOCX template variables & Barcode QR Code TTE inside native XML
        $parser = new DocxParserService();
        $processedDocxPath = $parser->processDocxTemplate($path, $document);

        // Convert processed DOCX to PDF using LibreOffice Headless (100% presisi)
        $pdfPath = $this->convertDocxToPdf($processedDocxPath, $document, $version);

        $suffix = $document->signature ? '_TTE' : '_DRAFT';
        $safeFilename = Str::slug($document->nomor_surat ?? $document->judul) . $suffix . '.pdf';

        return response()->download($pdfPath, $safeFilename, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
