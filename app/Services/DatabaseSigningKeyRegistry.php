<?php

namespace App\Services;

use App\Contracts\SigningKeyRegistry;
use App\Models\SigningKey;
use App\Support\SigningKeyDescriptor;
use RuntimeException;

class DatabaseSigningKeyRegistry implements SigningKeyRegistry
{
    public function active(): SigningKeyDescriptor
    {
        $key = SigningKey::where('status', 'active')->where('activated_at', '<=', now())->orderByDesc('activated_at')->first();
        if (! $key) {
            throw new RuntimeException('Tidak ada public signing key aktif pada registry.');
        }

        return $this->descriptor($key);
    }

    public function find(string $keyId): ?SigningKeyDescriptor
    {
        $key = SigningKey::where('key_id', $keyId)->first();

        return $key ? $this->descriptor($key) : null;
    }

    private function descriptor(SigningKey $key): SigningKeyDescriptor
    {
        return new SigningKeyDescriptor($key->key_id, $key->algorithm, $key->public_key, $key->fingerprint, $key->status);
    }
}
