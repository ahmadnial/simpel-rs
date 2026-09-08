<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use App\Models\DocumentVerification;
use App\Models\Unit;
use App\Services\DocumentService;
use Illuminate\Http\Request;

class VerifikasiController extends Controller
{
    protected DocumentService $documentService;

    public function __construct(DocumentService $documentService)
    {
        $this->documentService = $documentService;
    }

    public function index(Request $request)
    {
        $user = auth()->user();

        // Ambil antrian untuk user ini atau pejabat yang di-delegasikan (Plt/Plh)
        $pejabatIds = [$user->id];
        if ($user->activeDelegation()) {
            $pejabatIds[] = $user->activeDelegation()->pejabat_id;
        }

        $antrianQuery = DocumentVerification::actionable((int) $user->id)
            ->with(['document.documentType', 'document.pengusul', 'document.unit'])
            ->whereIn('verifikator_id', $pejabatIds);

        // Filter Jenis Naskah / Klasifikasi Dokumen
        if ($request->filled('document_type_id')) {
            $antrianQuery->whereHas('document', function ($q) use ($request) {
                $q->where('document_type_id', $request->document_type_id);
            });
        }

        // Filter Unit Kerja / Instalasi
        if ($request->filled('unit_id')) {
            $antrianQuery->whereHas('document', function ($q) use ($request) {
                $q->where('unit_id', $request->unit_id);
            });
        }

        // Filter Search Keyword
        if ($request->filled('search')) {
            $search = $request->search;
            $antrianQuery->whereHas('document', function ($q) use ($search) {
                $q->where('judul', 'like', "%{$search}%")
                    ->orWhere('nomor_surat', 'like', "%{$search}%")
                    ->orWhereHas('pengusul', function ($pu) use ($search) {
                        $pu->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $antrian = $antrianQuery->latest()->paginate(10)->withQueryString();

        // Data Master untuk Filter Dropdown
        $documentTypes = DocumentType::orderBy('nama')->get();
        $units = Unit::orderBy('nama')->get();

        $riwayatQuery = DocumentVerification::whereIn('verifikator_id', $pejabatIds)
            ->where('status', '!=', DocumentVerification::STATUS_MENUNGGU)
            ->with([
                'document.documentType', 'document.pengusul', 'document.unit',
                'verifikator', 'decisionMaker',
                'closingDecision.verifikator', 'closingDecision.decisionMaker',
            ])
            ->orderByRaw('COALESCE(document_verifications.direspon_at, document_verifications.direset_at, document_verifications.created_at) DESC')
            ->orderByDesc('id');

        $riwayat = $riwayatQuery->paginate(10, ['*'], 'riwayat_page')->withQueryString();

        return view('verifikasi.index', compact('antrian', 'riwayat', 'documentTypes', 'units'));
    }

    public function show(DocumentVerification $verification)
    {
        $this->checkAccess($verification);

        $verification->load([
            'reopenedFrom', 'workflowStep', 'verifikator', 'decisionMaker',
            'closingDecision.verifikator', 'closingDecision.decisionMaker',
            'document.documentType', 'document.unit', 'document.pengusul', 'document.currentVersion',
            'document.versions.uploader', 'document.verifications.verifikator', 'document.verifications.version',
        ]);

        return response()->view('verifikasi.show', compact('verification'))
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function setujui(Request $request, DocumentVerification $verification)
    {
        $this->checkAccess($verification);

        $request->validate([
            'catatan' => 'nullable|string|max:1000',
        ]);

        $this->documentService->setujui($verification, $request->catatan);

        return redirect()->route('verifikasi.index')->with('success', 'Dokumen berhasil disetujui.');
    }

    public function mintaRevisi(Request $request, DocumentVerification $verification)
    {
        $this->checkAccess($verification);

        $request->validate([
            'catatan' => 'required|string|max:1000',
        ]);

        $this->documentService->mintaRevisi($verification, $request->catatan);

        return redirect()->route('verifikasi.index')->with('success', 'Catatan revisi berhasil dikirim ke pengusul.');
    }

    public function teruskanBawah(Request $request, DocumentVerification $verification)
    {
        $this->checkAccess($verification);

        $request->validate([
            'catatan' => 'required|string|max:1000',
        ]);

        $this->documentService->turunkanKeVerifikatorBawah($verification, $request->catatan);

        return redirect()->route('verifikasi.index')->with('success', 'Dokumen berhasil dikembalikan ke verifikator tingkat sebelumnya.');
    }

    /** Cek hak akses aksi: hanya pemilik tiket atau Plt/Plh aktifnya. */
    private function checkAccess(DocumentVerification $verification): void
    {
        $user = auth()->user();

        $isDirectVerifikator = $verification->verifikator_id === $user->id;
        $isDelegate = $user->activeDelegation() && $user->activeDelegation()->pejabat_id === $verification->verifikator_id;

        abort_unless(
            $isDirectVerifikator || $isDelegate,
            403,
            'Anda tidak memiliki wewenang untuk memverifikasi dokumen ini.'
        );
    }
}
