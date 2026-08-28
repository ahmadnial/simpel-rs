<?php

namespace App\Http\Controllers;

use App\Models\DocumentSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PublicVerifyController extends Controller
{
    public function show(string $token)
    {
        $signature = DocumentSignature::where('qr_token', $token)
            ->with(['document.documentType', 'document.unit', 'penandatangan', 'delegasi'])
            ->first();

        $integrityValid = null;
        if ($signature) {
            $integrityValid = $signature->file_signed_path
                && Storage::disk('local')->exists($signature->file_signed_path)
                && hash_equals(
                    $signature->hash_dokumen,
                    hash_file('sha256', Storage::disk('local')->path($signature->file_signed_path))
                );
        }

        return view('public.verify', compact('signature', 'integrityValid'));
    }
}
