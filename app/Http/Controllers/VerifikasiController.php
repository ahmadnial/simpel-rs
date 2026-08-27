<?php

namespace App\Http\Controllers;

use App\Models\DocumentVerification;
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

        $antrianQuery = DocumentVerification::where('status', DocumentVerification::STATUS_MENUNGGU)
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
        $documentTypes = \App\Models\DocumentType::orderBy('nama')->get();
        $units = \App\Models\Unit::orderBy('nama')->get();

        $riwayatQuery = DocumentVerification::whereIn('verifikator_id', $pejabatIds)
            ->where('status', '!=', DocumentVerification::STATUS_MENUNGGU)
            ->with(['document.documentType', 'document.pengusul', 'document.unit']);

        $riwayat = $riwayatQuery->latest()->paginate(10, ['*'], 'riwayat_page')->withQueryString();

        return view('verifikasi.index', compact('antrian', 'riwayat', 'documentTypes', 'units'));
    }

    public function show(DocumentVerification $verification)
    {
        $this->checkAccess($verification);

        $verification->load([
            'document.documentType', 'document.unit', 'document.pengusul',
            'document.versions.uploader', 'document.verifications.verifikator'
        ]);

        return view('verifikasi.show', compact('verification'));
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

    /**
     * Cek hak akses verifikasi (pemilik antrian, Plt/Plh, super_admin, atau role verifikator)
     */
    private function checkAccess(DocumentVerification $verification): void
    {
        $user = auth()->user();

        $isDirectVerifikator = $verification->verifikator_id === $user->id;
        $isSuperAdmin        = $user->hasRole('super_admin');
        $hasPermission       = $user->hasPermissionTo('dokumen.verifikasi');
        $isDelegate          = $user->activeDelegation() && $user->activeDelegation()->pejabat_id === $verification->verifikator_id;

        abort_unless(
            $isDirectVerifikator || $isSuperAdmin || $hasPermission || $isDelegate,
            403,
            'Anda tidak memiliki wewenang untuk memverifikasi dokumen ini.'
        );
    }
}
