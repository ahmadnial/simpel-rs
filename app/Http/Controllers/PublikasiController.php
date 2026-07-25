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
            ->with(['documentType', 'unit', 'pengusul', 'signature'])
            ->latest()
            ->paginate(10, ['*'], 'published_page');

        return view('publikasi.index', compact('siapPublikasi', 'dipublikasikan'));
    }

    public function publikasi(Document $document)
    {
        $this->documentService->publikasi($document);

        return back()->with('success', "Dokumen '{$document->nomor_surat}' telah dipublikasikan.");
    }
}
