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

    public function index(Request $request)
    {
        $user = auth()->user();

        // Roles yang dimiliki (termasuk delegasi Plt/Plh)
        $signerRoles = $user->getRoleNames()->toArray();
        if ($delegated = $user->activeDelegation()) {
            if ($delegated->pejabat) {
                $signerRoles = array_unique(array_merge($signerRoles, $delegated->pejabat->getRoleNames()->toArray()));
            }
        }

        $antrianQuery = Document::where('status', Document::STATUS_MENUNGGU_TTD)
            ->with(['documentType', 'unit', 'pengusul', 'currentVersion']);

        $antrianQuery->whereHas('workflowTemplate.steps', function ($q) use ($signerRoles) {
            $q->where('tipe', 'penandatangan')->whereIn('role_nama', $signerRoles);
        });

        // Filter Jenis Naskah / Klasifikasi Dokumen
        if ($request->filled('document_type_id')) {
            $antrianQuery->where('document_type_id', $request->document_type_id);
        }

        // Filter Unit Kerja / Instalasi
        if ($request->filled('unit_id')) {
            $antrianQuery->where('unit_id', $request->unit_id);
        }

        // Filter Search Keyword
        if ($request->filled('search')) {
            $search = $request->search;
            $antrianQuery->where(function ($q) use ($search) {
                $q->where('judul', 'like', "%{$search}%")
                  ->orWhere('nomor_surat', 'like', "%{$search}%")
                  ->orWhereHas('pengusul', function ($pu) use ($search) {
                      $pu->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $antrian = $antrianQuery->latest()->paginate(10)->withQueryString();

        // Data Master untuk Filter Dropdown
        $documentTypes = \App\Models\DocumentType::orderBy('nama')->get();
        $units = \App\Models\Unit::orderBy('nama')->get();

        return view('tanda-tangan.index', compact('antrian', 'documentTypes', 'units'));
    }

    public function show(Document $document)
    {
        $user = auth()->user();

        $this->documentService->assertCanSign($document, $user);

        $document->load([
            'documentType', 'unit', 'pengusul',
            'currentVersion', 'verifications.verifikator'
        ]);

        $currentVersionId = $document->currentVersion?->id;
        $returnTarget = $document->verifications
            ->where('document_version_id', $currentVersionId)
            ->where('status', \App\Models\DocumentVerification::STATUS_DISETUJUI)
            ->sortByDesc('level')
            ->groupBy('level')
            ->first();

        return view('tanda-tangan.show', compact('document', 'returnTarget'));
    }

    public function kirimOtp(Request $request, Document $document)
    {
        $user = auth()->user();
        $this->documentService->assertCanSign($document, $user);
        $expiryMinutes = config('app.otp_expiry_minutes', 5);
        $otp = $user->generateOtp($document);

        $user->notify(new \App\Notifications\OtpTandaTangan($otp, $expiryMinutes));

        $response = [
            'success' => true,
            'message' => "OTP berhasil dikirim ke email terdaftar Anda (berlaku {$expiryMinutes} menit).",
        ];

        // Kode OTP asli sebelumnya selalu dikembalikan di response & session flash,
        // sehingga tidak berfungsi sebagai faktor otentikasi kedua yang sesungguhnya.
        // Sekarang hanya disertakan saat APP_DEBUG=true (local/testing), tidak pernah di produksi.
        if (config('app.debug')) {
            $response['debug_otp'] = $otp;
        }

        return response()->json($response);
    }

    public function tandatangani(Request $request, Document $document)
    {
        $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        try {
            $this->documentService->tandaTangani($document, $request->otp);
            return redirect()->route('ttd.index')->with('success', "Dokumen '{$document->judul}' berhasil disahkan secara elektronik di SIMPEL-RS.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function tolak(Request $request, Document $document)
    {
        $request->validate([
            'alasan_tolak' => 'required|string|min:10|max:1000',
        ]);

        try {
            $this->documentService->tolakTandaTangan($document, $request->alasan_tolak);
            return redirect()->route('ttd.index')
                ->with('success', "Dokumen dikembalikan. Verifikator terkait telah dinotifikasi.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
