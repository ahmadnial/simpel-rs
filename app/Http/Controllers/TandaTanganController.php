<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\DocumentService;
use Illuminate\Http\Request;

class TandaTanganController extends Controller
{
    protected DocumentService $documentService;

    public function __construct(DocumentService $documentService)
    {
        $this->documentService = $documentService;
    }

    public function index()
    {
        $user = auth()->user();

        $antrian = Document::where('status', Document::STATUS_MENUNGGU_TTD)
            ->with(['documentType', 'unit', 'pengusul', 'currentVersion'])
            ->latest()
            ->paginate(10);

        return view('tanda-tangan.index', compact('antrian'));
    }

    public function show(Document $document)
    {
        $user = auth()->user();

        abort_unless(
            $document->status === Document::STATUS_MENUNGGU_TTD && ($user->hasPermissionTo('dokumen.tanda_tangan') || $user->hasRole('super_admin')),
            403,
            'Dokumen tidak dalam antrian tanda tangan atau Anda tidak memiliki wewenang.'
        );

        $document->load([
            'documentType', 'unit', 'pengusul',
            'currentVersion', 'verifications.verifikator'
        ]);

        return view('tanda-tangan.show', compact('document'));
    }

    public function kirimOtp(Request $request)
    {
        $user = auth()->user();
        $otp = $user->generateOtp();

        session()->flash('otp_debug', "Kode OTP Anda: {$otp} (berlaku 5 menit)");

        return response()->json([
            'success' => true,
            'message' => "OTP berhasil dikirim. (Kode Test: {$otp})"
        ]);
    }

    public function tandatangani(Request $request, Document $document)
    {
        $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        try {
            $this->documentService->tandaTangani($document, $request->otp);
            return redirect()->route('ttd.index')->with('success', "Dokumen '{$document->judul}' berhasil ditandatangani secara elektronik (TTE).");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
