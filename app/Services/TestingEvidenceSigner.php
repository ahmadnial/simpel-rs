<?php

namespace App\Services;

use App\Contracts\EvidenceSigner;
use App\Contracts\SigningKeyRegistry;
use App\Support\EvidenceSignature;
use App\Support\SigningKeyDescriptor;
use LogicException;

class TestingEvidenceSigner implements EvidenceSigner, SigningKeyRegistry
{
    private SigningKeyDescriptor $descriptor;

    private string $secretKey;

    public function __construct()
    {
        if (! app()->environment('testing')) {
            throw new LogicException('TestingEvidenceSigner hanya boleh digunakan oleh automated test.');
        }
        $seed = hash('sha256', 'simpel-rs-testing-ed25519-seed', true);
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->descriptor = new SigningKeyDescriptor(
            keyId: 'testing-ed25519-2026-01',
            algorithm: 'Ed25519',
            publicKey: base64_encode($publicKey),
            fingerprint: hash('sha256', $publicKey),
            status: 'active',
        );
    }

    public function activeKey(): SigningKeyDescriptor
    {
        return $this->descriptor;
    }

    public function sign(string $canonicalManifest): EvidenceSignature
    {
        return new EvidenceSignature(
            $this->descriptor,
            base64_encode(sodium_crypto_sign_detached($canonicalManifest, $this->secretKey)),
        );
    }

    public function active(): SigningKeyDescriptor
    {
        return $this->descriptor;
    }

    public function find(string $keyId): ?SigningKeyDescriptor
    {
        return hash_equals($this->descriptor->keyId, $keyId) ? $this->descriptor : null;
    }
}
