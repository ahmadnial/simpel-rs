<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\DocumentService;
use Illuminate\Http\Request;

class PublikasiController extends Controller
{
    protected DocumentService $documentService;

    public function __construct(DocumentService $documentService)
    {
        $this->documentService = $documentService;
    }

    public function index()
    {
        $siapPublikasi = Document::where('status', Document::STATUS_DITANDATANGANI)
            ->with(['documentType', 'unit', 'pengusul', 'signature'])
            ->latest()
            ->paginate(10);

        $dipublikasikan = Document::where('status', Document::STATUS_DIPUBLIKASIKAN)
            ->with(['documentType', 'unit', 'pengusul', 'signature', 'distributions.unit'])
            ->latest()
            ->paginate(10, ['*'], 'published_page');

        $ditarik = Document::where('status', Document::STATUS_DITARIK)
            ->with(['documentType', 'unit', 'pengusul', 'penggantiDocument'])
            ->latest('ditarik_at')
            ->paginate(10, ['*'], 'withdrawn_page');

        $units = \App\Models\Unit::active()->get();

        // Dokumen yang bisa dijadikan pengganti (sudah ditandatangani/dipublikasikan)
        $dokumenPengganti = Document::whereIn('status', [
            Document::STATUS_DITANDATANGANI,
            Document::STATUS_DIPUBLIKASIKAN,
        ])->select('id', 'nomor_surat', 'judul')->latest()->get();

        return view('publikasi.index', compact(
            'siapPublikasi', 'dipublikasikan', 'ditarik', 'units', 'dokumenPengganti'
        ));
    }

    public function publikasi(Request $request, Document $document)
    {
        $validated = $request->validate([
            'visibility_scope' => 'required|in:terbatas,unit,internal',
            'unit_ids'         => 'nullable|array',
            'unit_ids.*'       => 'exists:units,id',
        ]);

        $this->documentService->publikasi($document, $validated);

        return back()->with('success', "Dokumen '{$document->nomor_surat}' telah berhasil dipublikasikan.");
    }

    /**
     * Tarik dokumen dari publikasi (unpublish).
     */
    public function unpublish(Request $request, Document $document)
    {
        $validated = $request->validate([
            'alasan_penarikan'      => 'required|string|max:1000',
            'pengganti_document_id' => 'nullable|exists:documents,id',
        ]);

        $this->documentService->unpublish(
            $document,
            $validated['alasan_penarikan'],
            $validated['pengganti_document_id'] ?? null
        );

        return back()->with('success', "Dokumen '{$document->nomor_surat}' telah berhasil ditarik dari publikasi.");
    }

    /**
     * Publikasikan ulang dokumen yang sebelumnya ditarik.
     */
    public function republish(Request $request, Document $document)
    {
        $validated = $request->validate([
            'visibility_scope' => 'required|in:terbatas,unit,internal',
            'unit_ids'         => 'nullable|array',
            'unit_ids.*'       => 'exists:units,id',
        ]);

        $this->documentService->republish($document, $validated);

        return back()->with('success', "Dokumen '{$document->nomor_surat}' telah berhasil dipublikasikan ulang.");
    }
}
