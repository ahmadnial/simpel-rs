<?php

namespace App\Http\Controllers;

use App\Exceptions\DocumentConversionException;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentPdfService;
use App\Services\DocumentService;
use App\Services\DocxParserService;
use App\Services\DocxTemplateProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentController extends Controller
{
    protected DocumentService $documentService;

    public function __construct(DocumentService $documentService, private readonly DocumentPdfService $pdfService)
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
            $query->where('judul', 'like', '%'.$request->search.'%');
        }

        $documents = $query->latest()->paginate(10);

        return view('dokumen.index', compact('documents'));
    }

    public function create()
    {
        $documentTypes = DocumentType::active()->get();
        // Khusus role 'asesor_internal' — pool tahap 1 yang dipilih manual pengusul dengan
        // semantik "salah satu approve = sah". Bukan semua pemegang permission dokumen.verifikasi
        // (yang juga mencakup verifikator role_nama-driven di tahap-tahap lain).
        $verifikators = User::role('asesor_internal')
            ->where('is_active', true)
            ->where('id', '!=', auth()->id())
            ->get();

        // Picker "Ajukan ke Asesor Internal" cuma relevan untuk jenis naskah yang tahap 1
        // workflow-nya sengaja dikosongkan (serial, tanpa role_nama) — pengusul yang pilih manual.
        // Kalau tahap 1 sudah punya pool otomatis (parallel, atau serial dengan role_nama), pilihan
        // manual pengusul akan diabaikan (lihat DocumentService::createVerificationsForStep), jadi
        // form JS menyembunyikan picker untuk jenis naskah itu berdasarkan map ini.
        $unitId = auth()->user()->unit_id;
        $workflowStepInfo = $documentTypes->mapWithKeys(
            fn (DocumentType $dt) => [$dt->id => $this->documentService->getFirstStepInfo($dt, $unitId)]
        );
        // Pratinjau rantai alur lengkap per jenis naskah, ditampilkan di form supaya pengusul
        // tahu siapa saja yang akan memeriksa & menandatangani sebelum mengajukan.
        $workflowChainInfo = $documentTypes->mapWithKeys(
            fn (DocumentType $dt) => [$dt->id => $this->documentService->getWorkflowChainPreview($dt, $unitId)]
        );

        return view('dokumen.create', compact('documentTypes', 'verifikators', 'workflowStepInfo', 'workflowChainInfo'));
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Document::class);

        $validated = $request->validate([
            'judul' => 'required|string|max:255',
            'document_type_id' => 'required|exists:document_types,id',
            'perihal' => 'nullable|string|max:255',
            'keterangan' => 'nullable|string',
            'file_dokumen' => 'required|file|max:10240',
            'submit_mode' => 'nullable|in:ajukan,internal',
            'verifikator_ids' => 'nullable|array',
            'verifikator_ids.*' => ['exists:users,id', $this->verifikatorRule()],
            // Dipakai saat tahap 1 workflow jenis naskah ini sudah otomatis (pool/role_nama) —
            // tidak ada picker manual untuk dipilih, cukup konfirmasi mau langsung diajukan.
            'ajukan_langsung' => 'nullable|boolean',
            'is_rahasia' => 'nullable|boolean',
        ], $this->uploadMessages());

        $verifikatorIds = $validated['verifikator_ids'] ?? [];
        $wantsAjukan = ($validated['submit_mode'] ?? 'ajukan') === 'ajukan';
        if ($wantsAjukan && empty($verifikatorIds) && ! $request->boolean('ajukan_langsung')) {
            throw ValidationException::withMessages([
                'submit_mode' => 'Dokumen belum disimpan karena alur verifikasi atau verifikator tahap pertama belum tersedia.',
            ]);
        }

        try {
            $document = $this->documentService->uploadDraft([
                'judul' => $validated['judul'],
                'document_type_id' => $validated['document_type_id'],
                'unit_id' => auth()->user()->unit_id,
                'perihal' => $validated['perihal'] ?? null,
                'keterangan' => $validated['keterangan'] ?? null,
                'is_rahasia' => $request->boolean('is_rahasia'),
            ], $request->file('file_dokumen'));
        } catch (DocumentConversionException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return back()->withInput()->withErrors(['file_dokumen' => $exception->getMessage()]);
        }

        $request->session()->put('document_submission.'.$document->id, [
            'verifikator_ids' => $verifikatorIds,
            'submit_mode' => $wantsAjukan ? 'ajukan' : 'internal',
        ]);
        if ($wantsAjukan) {
            return redirect()->route('dokumen.show', $document)->with('success', 'Dokumen tersimpan sebagai draft. Periksa pratinjau, lalu ajukan jika sudah sesuai.');
        }

        return redirect()->route('dokumen.show', $document)->with('success', 'Dokumen tersimpan sebagai Arsip Internal Unit.');
    }

    public function show(Document $document)
    {
        abort_unless(
            $document->isAccessibleBy(auth()->user()),
            403,
            'Anda tidak memiliki hak akses untuk melihat naskah dinas ini.'
        );

        $document->load([
            'documentType', 'unit', 'pengusul',
            'versions.uploader', 'verifications.verifikator', 'verifications.version',
            'verifications.decisionMaker', 'verifications.closingDecision.verifikator',
            'verifications.closingDecision.decisionMaker',
            'signature.penandatangan', 'distributions.unit',
            'penggantiDocument', 'auditLogs',
        ]);

        // Khusus role 'asesor_internal' — pool tahap 1 yang dipilih manual pengusul dengan
        // semantik "salah satu approve = sah". Bukan semua pemegang permission dokumen.verifikasi
        // (yang juga mencakup verifikator role_nama-driven di tahap-tahap lain).
        $verifikators = User::role('asesor_internal')
            ->where('is_active', true)
            ->where('id', '!=', auth()->id())
            ->get();

        $firstStepInfo = $this->documentService->getFirstStepInfo($document->documentType, $document->unit_id);
        $workflowChain = $this->documentService->getWorkflowChainPreview($document->documentType, $document->unit_id);

        return view('dokumen.show', compact('document', 'verifikators', 'firstStepInfo', 'workflowChain'));
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
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir.'/'.uniqid('editor_').'.docx';

        $this->createDocxFromHtml($htmlContent, $tempFile);

        // Simpan sebagai versi baru menggunakan UploadedFile
        $uploadedFile = new UploadedFile(
            $tempFile,
            $document->judul.'.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true // test mode agar tidak validasi is_uploaded_file()
        );

        $this->documentService->simpanVersi($document, $uploadedFile, $catatan);

        // Hapus temp file
        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }

        return redirect()->route('dokumen.show', $document)->with('success', 'Perubahan naskah dinas berhasil disimpan. Periksa pratinjau sebelum mengajukan.');
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

        $zip = new \ZipArchive;
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
  <w:body>'.$bodyXml.'
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
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body) {
            // Fallback: buat paragraf dari plain text
            $text = strip_tags($html);

            return '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($text).'</w:t></w:r></w:p>';
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
            if (trim($text) === '') {
                return '';
            }

            return '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($text).'</w:t></w:r></w:p>';
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

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
                $xml .= '<w:pPr><w:pStyle w:val="Heading'.$level.'"/></w:pPr>';
                $xml .= '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'.htmlspecialchars($text).'</w:t></w:r>';
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
                        $xml .= '<w:r><w:t xml:space="preserve">• '.htmlspecialchars($li->textContent).'</w:t></w:r>';
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
                                        $xml .= '<w:tc><w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($cell->textContent).'</w:t></w:r></w:p></w:tc>';
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
                    $runs .= '<w:r><w:t xml:space="preserve">'.htmlspecialchars($text).'</w:t></w:r>';
                }
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($child->nodeName);
                $rPr = '';

                if (in_array($tag, ['b', 'strong'])) {
                    $rPr .= '<w:b/>';
                }
                if (in_array($tag, ['i', 'em'])) {
                    $rPr .= '<w:i/>';
                }
                if ($tag === 'u') {
                    $rPr .= '<w:u w:val="single"/>';
                }

                if ($rPr) {
                    $runs .= '<w:r><w:rPr>'.$rPr.'</w:rPr><w:t xml:space="preserve">'.htmlspecialchars($child->textContent).'</w:t></w:r>';
                } elseif ($tag === 'br') {
                    $runs .= '<w:r><w:br/></w:r>';
                } else {
                    $runs .= '<w:r><w:t xml:space="preserve">'.htmlspecialchars($child->textContent).'</w:t></w:r>';
                }
            }
        }

        return $runs;
    }

    public function preview(Document $document, $versionId = null)
    {
        abort_unless($document->isAccessibleBy(auth()->user()), 403, 'Anda tidak memiliki hak akses untuk melihat naskah dinas ini.');

        $version = $versionId ? $document->versions()->find($versionId) : $document->currentVersion;
        if (! $version) {
            $version = $document->versions()->first();
        }
        if (! $version) {
            abort(404, 'File versi tidak ditemukan.');
        }

        $path = $this->documentService->ensureDocxFileExists($document, $version);
        $processedPath = (new DocxParserService)->processDocxTemplate($path, $document);

        return response()->file($processedPath, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'inline; filename="'.$version->file_name.'"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public function previewPdf(Request $request, Document $document, $versionId = null)
    {
        abort_unless($document->isAccessibleBy($request->user()), 403);
        $version = $versionId ? $document->versions()->findOrFail($versionId) : $document->currentVersion;
        abort_unless($version, 404, 'File versi tidak ditemukan.');
        $headers = ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store'];
        $official = $document->signature;
        if ($official && $official->document_version_id === $version->id && $official->file_signed_path) {
            // An official PDF must not depend on a source file or converter still being available.
            $pdfPath = Storage::disk('local')->path($official->file_signed_path);
        } elseif ($version->file_pdf_path && ! $document->isDraft() && ! $document->isRevisi()) {
            $pdfPath = Storage::disk('local')->path($version->file_pdf_path);
        } else {
            $source = $this->documentService->ensureDocxFileExists($document, $version);
            $processor = app(DocxTemplateProcessor::class);
            $inspection = $processor->inspect($source);
            $processed = $processor->process($source, $document, $request->boolean('markers', true));
            $pdfPath = $this->pdfService->render($processed, $document, $version);
            $warnings = [];
            if (! $inspection['has_qr']) {
                $warnings[] = 'Variabel QR belum ditemukan; posisi QR pengesahan belum dapat diperiksa.';
            }
            if ($inspection['unknown']) {
                $warnings[] = 'Variabel tidak dikenal: '.implode(', ', $inspection['unknown']).'. Perbaiki sebelum mengajukan.';
            }
            $headers['X-Preview-Warnings'] = rawurlencode(implode(' ', $warnings));
            if (! $inspection['unknown'] && $request->user()->can('update', $document)) {
                $headers['X-Document-Review'] = Crypt::encryptString(json_encode([
                    'user_id' => $request->user()->id,
                    'document_id' => $document->id,
                    'version_id' => $version->id,
                    'source_hash' => hash_file('sha256', $source),
                    'source_path' => $version->file_path,
                    'pdf_hash' => hash_file('sha256', $pdfPath),
                    'pdf_path' => $pdfPath,
                    'expires_at' => now()->addDay()->timestamp,
                ], JSON_THROW_ON_ERROR));
            }
        }
        abort_unless(is_file($pdfPath), 404, 'PDF tidak ditemukan. Hubungi administrator.');

        return response()->file($pdfPath, $headers);
    }

    public function uploadVersi(Request $request, Document $document)
    {
        Gate::authorize('update', $document);

        $request->validate([
            'file_dokumen' => 'required|file|max:10240',
            'catatan' => 'nullable|string|max:500',
        ], $this->uploadMessages());

        try {
            $this->documentService->simpanVersi($document, $request->file('file_dokumen'), $request->catatan);
        } catch (DocumentConversionException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return back()->withInput()->withErrors(['file_dokumen' => $exception->getMessage()]);
        }

        return redirect()->route('dokumen.show', $document)->with('success', 'Berkas tersimpan. Periksa pratinjau terbaru sebelum mengajukan.');
    }

    public function ajukan(Request $request, Document $document)
    {
        Gate::authorize('update', $document);

        $validated = $request->validate([
            'verifikator_ids' => 'nullable|array',
            'verifikator_ids.*' => ['exists:users,id', $this->verifikatorRule()],
            'ajukan_langsung' => 'nullable|boolean',
            'review_token' => 'required|string',
        ], ['review_token.required' => 'Muat dan periksa pratinjau versi terbaru sebelum mengajukan.']);

        $verifikatorIds = $validated['verifikator_ids'] ?? [];
        abort_unless(
            ! empty($verifikatorIds) || $request->boolean('ajukan_langsung'),
            422,
            'Pilih minimal 1 Asesor Internal, atau konfirmasi pengajuan langsung.'
        );

        try {
            $review = json_decode(Crypt::decryptString($validated['review_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['review_token' => 'Pratinjau tidak valid. Muat ulang pratinjau sebelum mengajukan.']);
        }
        DB::transaction(function () use ($document, $request, $review, $verifikatorIds) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $version = $locked->currentVersion;
            $source = $version ? Storage::disk('local')->path($version->file_path) : null;
            $valid = ($review['user_id'] ?? null) === $request->user()->id
                && ($review['document_id'] ?? null) === $locked->id
                && ($review['version_id'] ?? null) === $version?->id
                && ($review['source_path'] ?? null) === $version?->file_path
                && ($review['expires_at'] ?? 0) > now()->timestamp
                && $source && is_file($source)
                && hash_equals($review['source_hash'] ?? '', hash_file('sha256', $source))
                && is_file($review['pdf_path'] ?? '')
                && hash_equals($review['pdf_hash'] ?? '', hash_file('sha256', $review['pdf_path']));
            if (! $valid) {
                throw ValidationException::withMessages(['review_token' => 'Versi dokumen berubah atau pratinjau kedaluwarsa. Muat dan periksa pratinjau terbaru.']);
            }
            $snapshot = "documents/{$locked->id}/reviewed/{$version->id}.pdf";
            abort_unless(Storage::disk('local')->put($snapshot, file_get_contents($review['pdf_path'])), 503, 'PDF yang diperiksa belum dapat disimpan. Coba lagi.');
            $version->update(['file_pdf_path' => $snapshot]);
            $this->documentService->ajukanDokumen($locked, $verifikatorIds);
        });
        $request->session()->forget('document_submission.'.$document->id);

        return back()->with('success', 'Dokumen berhasil diajukan ke verifikator.');
    }

    /**
     * Aturan validasi verifikator: harus user aktif, punya role 'asesor_internal',
     * dan bukan pengusul sendiri (mencegah self-approval). Dipakai di store() & ajukan()
     * agar konsisten dengan daftar dropdown verifikator yang ditampilkan di show()/create().
     */
    private function verifikatorRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            $target = User::find($value);
            $isEligible = $target
                && $target->is_active
                && $target->id !== auth()->id()
                && $target->hasRole('asesor_internal');

            if (! $isEligible) {
                $fail('Verifikator yang dipilih tidak valid, tidak aktif, atau tidak memiliki wewenang verifikasi.');
            }
        };
    }

    public function download(Document $document, $versionId = null)
    {
        return $this->downloadPdf($document, $versionId);
    }

    public function downloadPdf(Document $document, $versionId = null)
    {
        abort_unless(
            $document->isAccessibleBy(auth()->user()),
            403,
            'Anda tidak memiliki hak akses untuk mengunduh naskah dinas ini.'
        );

        $version = $versionId ? $document->versions()->find($versionId) : $document->currentVersion;
        $version = $version ?? $document->currentVersion ?? $document->versions()->first();

        if (! $version) {
            abort(444, 'File dokumen tidak ditemukan.');
        }

        if ($document->signature && $version->id !== $document->signature->document_version_id) {
            abort(403, 'Versi historis bukan naskah resmi yang disahkan. Unduh versi pengesahan yang tercatat.');
        }

        if ($document->signature?->file_signed_path) {
            $pdfPath = Storage::disk('local')->path($document->signature->file_signed_path);
        } elseif ($version->file_pdf_path && ! $document->isDraft() && ! $document->isRevisi()) {
            $pdfPath = Storage::disk('local')->path($version->file_pdf_path);
        } else {
            $path = $this->documentService->ensureDocxFileExists($document, $version);
            $processed = app(DocxTemplateProcessor::class)->process($path, $document);
            $pdfPath = $this->pdfService->render($processed, $document, $version);
        }

        $suffix = $document->signature ? '_TTE' : '_DRAFT';
        $safeFilename = Str::slug($document->nomor_surat ?? $document->judul).$suffix.'.pdf';

        return response()->download($pdfPath, $safeFilename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function uploadMessages(): array
    {
        return [
            'file_dokumen.required' => 'Pilih berkas Word .doc atau .docx terlebih dahulu.',
            'file_dokumen.file' => 'Unggahan tidak dapat dibaca. Pilih ulang berkas dan coba lagi.',
            'file_dokumen.uploaded' => 'Server gagal menerima unggahan. Jika ukuran masih di bawah 10 MB, hubungi administrator untuk memeriksa batas unggahan server.',
            'file_dokumen.max' => 'Ukuran berkas melebihi batas 10 MB. Kurangi ukuran gambar atau isi berkas sebelum mengunggah kembali.',
        ];
    }
}
