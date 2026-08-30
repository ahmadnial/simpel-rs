<?php

namespace App\Http\Controllers;

use App\Models\DocumentSignature;
use App\Services\EvidenceVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PublicVerifyController extends Controller
{
    public function show(string $token, EvidenceVerificationService $verifier)
    {
        [$signature, $integrityValid, $verification] = $this->recordVerification($token, $verifier);

        return $this->secureVerificationResponse(
            response()->view('public.verify', [
                'signature' => $signature,
                'integrityValid' => $integrityValid,
                'verification' => $verification,
                'fileVerification' => [
                    'status' => 'not_checked',
                    'message' => 'Belum ada PDF pengguna yang dibandingkan dengan hash dokumen resmi.',
                ],
            ])
        );
    }

    public function verifyUploadedPdf(Request $request, string $token, EvidenceVerificationService $verifier)
    {
        [$signature, $integrityValid, $verification] = $this->recordVerification($token, $verifier);
        abort_unless($signature, 404);
        $request->validate([
            'pdf' => ['required', 'file', 'max:'.config('tte.verifier.max_upload_kilobytes')],
        ], ['pdf.max' => 'Ukuran PDF melebihi batas pemeriksaan.', 'pdf.required' => 'Pilih file PDF yang akan diperiksa.']);
        $upload = $request->file('pdf');
        $path = $upload->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $bytes = file_get_contents($path);
        if (! in_array($mime, ['application/pdf', 'application/x-pdf'], true) || ! str_starts_with($bytes, '%PDF-')) {
            throw ValidationException::withMessages(['pdf' => 'File tidak dikenali sebagai PDF yang aman untuk diperiksa.']);
        }
        foreach ((array) config('tte.verifier.dangerous_pdf_tokens') as $tokenPattern) {
            if (stripos($bytes, $tokenPattern) !== false) {
                throw ValidationException::withMessages(['pdf' => 'PDF memuat fitur aktif/tertanam yang tidak diizinkan untuk verifier publik.']);
            }
        }

        $expectedHash = $signature->evidence?->pdf_hash ?? $signature->hash_dokumen;
        $actualHash = hash_file('sha256', $path);
        $matches = is_string($expectedHash) && hash_equals($expectedHash, $actualHash);
        $fileVerification = [
            'status' => $matches ? 'match' : 'mismatch',
            'message' => $matches
                ? 'Byte PDF yang diunggah cocok dengan evidence resmi.'
                : 'Byte PDF yang diunggah tidak cocok dengan evidence resmi.',
            'actual_hash' => $actualHash,
            'expected_hash' => $expectedHash,
        ];
        unset($bytes);

        return $this->secureVerificationResponse(
            response()->view('public.verify', compact('signature', 'integrityValid', 'verification', 'fileVerification'))
        );
    }

    public function downloadBundle(string $token)
    {
        $signature = DocumentSignature::where('qr_token', $token)->with('evidence')->firstOrFail();
        abort_unless($signature->evidence?->bundle_path && Storage::disk('local')->exists($signature->evidence->bundle_path), 404);

        return Storage::disk('local')->download(
            $signature->evidence->bundle_path,
            "evidence-{$signature->evidence->uuid}.zip",
            ['Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff']
        );
    }

    private function recordVerification(string $token, EvidenceVerificationService $verifier): array
    {
        $signature = DocumentSignature::where('qr_token', $token)
            ->with(['document.documentType', 'document.unit', 'penandatangan', 'delegasi', 'evidence.otpChallenge', 'evidence.statusEvents'])
            ->first();

        $integrityValid = null;
        $verification = null;
        if ($signature) {
            $integrityValid = $signature->file_signed_path
                && Storage::disk('local')->exists($signature->file_signed_path)
                && hash_equals(
                    $signature->hash_dokumen,
                    hash_file('sha256', Storage::disk('local')->path($signature->file_signed_path))
                );
            $verification = $signature->evidence
                ? $verifier->verifyEvidence($signature->evidence)
                : ['valid' => (bool) $integrityValid, 'checks' => ['legacy_pdf_hash' => (bool) $integrityValid], 'key_status' => 'not_available', 'administrative_status' => 'legacy_unknown'];
        }

        return [$signature, $integrityValid, $verification];
    }

    private function secureVerificationResponse(\Illuminate\Http\Response $response): \Illuminate\Http\Response
    {
        return $response
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'DENY')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }
}
