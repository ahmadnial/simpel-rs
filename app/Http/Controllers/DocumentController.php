<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        $request->validate([
            'content' => 'required|string',
            'catatan' => 'nullable|string|max:255',
        ]);

        // Simpan perbaikan dari editor web
        if ($request->hasFile('file_dokumen')) {
            $this->documentService->simpanVersi($document, $request->file('file_dokumen'), $request->catatan ?? 'Diubah via Editor Web');
        }

        return redirect()->route('dokumen.show', $document)->with('success', 'Perubahan naskah dinas berhasil disimpan.');
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

        $filePath = $version->file_path;
        $path = null;

        if (Storage::disk('local')->exists($filePath)) {
            $path = Storage::disk('local')->path($filePath);
        } elseif (file_exists(storage_path('app/' . $filePath))) {
            $path = storage_path('app/' . $filePath);
        } elseif (file_exists(storage_path('app/private/' . $filePath))) {
            $path = storage_path('app/private/' . $filePath);
        } else {
            abort(404, 'File fisik tidak ditemukan di storage: ' . $filePath);
        }

        return response()->file($path, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'inline; filename="' . $version->file_name . '"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public function uploadVersi(Request $request, Document $document)
    {
        $this->authorize('update', $document);

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

    public function download(Document $document, $versionId)
    {
        $version = $document->versions()->findOrFail($versionId);
        $filePath = $version->file_path;

        if (Storage::disk('local')->exists($filePath)) {
            $path = Storage::disk('local')->path($filePath);
        } elseif (file_exists(storage_path('app/' . $filePath))) {
            $path = storage_path('app/' . $filePath);
        } elseif (file_exists(storage_path('app/private/' . $filePath))) {
            $path = storage_path('app/private/' . $filePath);
        } else {
            abort(404, 'File tidak ditemukan.');
        }

        return response()->download($path, $version->file_name);
    }
}
