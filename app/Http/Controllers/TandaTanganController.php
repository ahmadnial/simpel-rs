<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\SigningCeremony;
use App\Services\DocumentService;
use App\Services\SigningOtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class TandaTanganController extends Controller
{
    protected DocumentService $documentService;

    public function __construct(
        DocumentService $documentService,
        private readonly SigningOtpService $signingOtpService,
    ) {
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

    public function show(Request $request, Document $document)
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

        $ceremony = null;
        $reauthenticationAge = $this->reauthenticationAge($request);
        if ($reauthenticationAge !== null && $reauthenticationAge <= config('tte.otp.reauthentication_max_age_seconds')) {
            $context = $this->documentService->prepareOtpContext($document, $user, $request->session()->getId(), $reauthenticationAge);
            $ceremony = SigningCeremony::where('uuid', $context['signing_ceremony_id'])->firstOrFail();
        }

        return view('tanda-tangan.show', compact('document', 'returnTarget', 'ceremony', 'reauthenticationAge'));
    }

    public function reauthenticate(Request $request, Document $document)
    {
        $this->documentService->assertCanSign($document, $request->user());
        $request->validate(['password' => ['required', 'string']]);
        abort_unless(Hash::check($request->string('password')->toString(), $request->user()->password), 422, 'Password tidak benar.');
        $request->session()->put('auth_password_confirmed_at', now()->timestamp);

        return redirect()->route('ttd.show', $document)->with('success', 'Identitas dikonfirmasi ulang. Periksa PDF final kandidat sebelum meminta OTP.');
    }

    public function candidatePdf(Document $document, SigningCeremony $ceremony)
    {
        $this->documentService->assertCanSign($document, auth()->user());
        abort_unless($ceremony->document_id === (int) $document->id && $ceremony->intended_actor_id === (int) auth()->id(), 403);
        abort_unless(in_array($ceremony->state, [SigningCeremony::STATE_AWAITING_USER_SIGNATURE, SigningCeremony::STATE_USER_SIGNED], true), 409);
        abort_unless($ceremony->candidate_pdf_path && Storage::disk('local')->exists($ceremony->candidate_pdf_path), 404);

        return response()->file(Storage::disk('local')->path($ceremony->candidate_pdf_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="final-candidate.pdf"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Document-SHA256' => $ceremony->candidate_pdf_hash,
        ]);
    }

    public function kirimOtp(Request $request, Document $document)
    {
        $user = auth()->user();
        $this->documentService->assertCanSign($document, $user);
        $reauthenticationAge = $this->reauthenticationAge($request);
        abort_if($reauthenticationAge === null || $reauthenticationAge > config('tte.otp.reauthentication_max_age_seconds'), 423, 'Konfirmasi ulang password diperlukan sebelum meminta OTP.');
        $context = $this->documentService->prepareOtpContext($document, $user, $request->session()->getId(), $reauthenticationAge);
        $context['correlation_id'] = (string) \Illuminate\Support\Str::uuid();
        $context['source_ip'] = $request->ip();
        $context['user_agent'] = $request->userAgent();
        $challenge = $this->signingOtpService->request($user, $document, $context);
        $expiryMinutes = (int) ceil(config('tte.otp.ttl_seconds') / 60);

        $response = [
            'success' => true,
            'message' => $challenge->getAttribute('display_otp')
                ? "OTP ditampilkan hanya untuk lingkungan lokal (berlaku {$expiryMinutes} menit)."
                : "OTP berhasil dikirim ke email terdaftar Anda (berlaku {$expiryMinutes} menit).",
            'challenge_id' => substr($challenge->uuid, 0, 8),
            'destination' => $challenge->masked_destination,
            'pdf_fingerprint' => strtoupper(implode('-', str_split(substr($challenge->pdf_hash, 0, 12), 4))),
            'expires_at' => $challenge->expires_at->utc()->toIso8601String(),
        ];
        if ($challenge->getAttribute('display_otp')) {
            $response['otp'] = $challenge->getAttribute('display_otp');
        }

        return response()->json($response);
    }

    public function tandatangani(Request $request, Document $document)
    {
        $request->validate([
            'otp' => 'required|digits:8',
        ]);

        try {
            $reauthenticationAge = $this->reauthenticationAge($request);
            abort_if($reauthenticationAge === null || $reauthenticationAge > config('tte.otp.reauthentication_max_age_seconds'), 423, 'Konfirmasi ulang password diperlukan sebelum tanda tangan.');
            $this->documentService->tandaTangani($document, $request->otp, $request->session()->getId(), $reauthenticationAge);
            return redirect()->route('ttd.index')->with('success', "Dokumen '{$document->judul}' berhasil disahkan secara elektronik di SIMPEL-RS.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function reauthenticationAge(Request $request): ?int
    {
        $confirmedAt = $request->session()->get('auth_password_confirmed_at');

        return is_numeric($confirmedAt) ? max(0, now()->timestamp - (int) $confirmedAt) : null;
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
