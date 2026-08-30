<?php

namespace App\Support;

readonly class SigningKeyDescriptor
{
    public function __construct(
        public string $keyId,
        public string $algorithm,
        public string $publicKey,
        public string $fingerprint,
        public string $status,
    ) {}

    public function toArray(): array
    {
        return [
            'algorithm' => $this->algorithm,
            'fingerprint' => $this->fingerprint,
            'key_id' => $this->keyId,
            'public_key' => $this->publicKey,
            'status' => $this->status,
        ];
    }
}
