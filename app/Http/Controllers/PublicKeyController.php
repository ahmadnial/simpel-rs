<?php

namespace App\Http\Controllers;

use App\Contracts\SigningKeyRegistry;

class PublicKeyController extends Controller
{
    public function active(SigningKeyRegistry $keys)
    {
        return response()->json($keys->active()->toArray(), headers: ['Cache-Control' => 'public, max-age=300']);
    }

    public function show(string $keyId, SigningKeyRegistry $keys)
    {
        $key = $keys->find($keyId);
        abort_unless($key, 404);

        return response()->json($key->toArray(), headers: ['Cache-Control' => 'public, max-age=300']);
    }
}
