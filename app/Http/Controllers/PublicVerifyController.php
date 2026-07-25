<?php

namespace App\Http\Controllers;

use App\Models\DocumentSignature;
use Illuminate\Http\Request;

class PublicVerifyController extends Controller
{
    public function show(string $token)
    {
        $signature = DocumentSignature::where('qr_token', $token)
            ->with(['document.documentType', 'document.unit', 'penandatangan', 'delegasi'])
            ->first();

        return view('public.verify', compact('signature'));
    }
}
