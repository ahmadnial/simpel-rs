<?php

namespace App\Services;

use App\Contracts\EvidenceSigner;
use App\Models\SigningKey;
use App\Support\EvidenceSignature;
use App\Support\SigningKeyDescriptor;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenBaoEvidenceSigner implements EvidenceSigner
{
    public function activeKey(): SigningKeyDescriptor
    {
        $name = $this->keyName();
        $data = $this->request()->get("/v1/transit/keys/{$name}")->throw()->json('data');
        $version = (int) ($data['latest_version'] ?? 0);
        $publicKey = (string) ($data['keys'][(string) $version]['public_key'] ?? '');
        $raw = base64_decode($publicKey, true);
        if ($version < 1 || $raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('Public key Ed25519 OpenBao tidak valid.');
        }
        $descriptor = new SigningKeyDescriptor(
            "openbao:{$name}:v{$version}", 'Ed25519', $publicKey, hash('sha256', $raw), 'active'
        );
        // firstOrCreate retries a concurrent unique-key insert, unlike updateOrCreate.
        $signingKey = SigningKey::firstOrCreate(['key_id' => $descriptor->keyId], [
            'algorithm' => $descriptor->algorithm,
            'purpose' => 'institutional_evidence_seal',
            'provider_reference' => "transit/keys/{$name}",
            'public_key' => $descriptor->publicKey,
            'fingerprint' => $descriptor->fingerprint,
            'status' => 'active',
            'activated_at' => now(),
            'policy_version' => 'openbao-transit-v1',
        ]);
        $signingKey->fill([
            'algorithm' => $descriptor->algorithm,
            'purpose' => 'institutional_evidence_seal',
            'provider_reference' => "transit/keys/{$name}",
            'public_key' => $descriptor->publicKey,
            'fingerprint' => $descriptor->fingerprint,
            'status' => 'active',
            'policy_version' => 'openbao-transit-v1',
        ]);
        if ($signingKey->isDirty()) {
            $signingKey->save();
        }

        return $descriptor;
    }

    public function sign(string $canonicalManifest): EvidenceSignature
    {
        $key = $this->activeKey();
        $signatureEnvelope = (string) $this->request()->post('/v1/transit/sign/'.$this->keyName(), [
            'input' => base64_encode($canonicalManifest),
        ])->throw()->json('data.signature');
        $parts = explode(':', $signatureEnvelope);
        $signature = end($parts);
        $raw = base64_decode($signature, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException('Signature Ed25519 OpenBao tidak valid.');
        }

        return new EvidenceSignature($key, $signature);
    }

    private function request(): PendingRequest
    {
        $token = (string) config('tte.providers.openbao.token');
        if (strlen($token) < 16) {
            throw new RuntimeException('Token OpenBao belum dikonfigurasi.');
        }

        return Http::baseUrl(rtrim((string) config('tte.providers.openbao.address'), '/'))
            ->withHeader('X-Vault-Token', $token)
            ->acceptJson()->timeout((int) config('tte.providers.openbao.timeout_seconds'))->retry(2, 150);
    }

    private function keyName(): string
    {
        return rawurlencode((string) config('tte.providers.openbao.transit_key'));
    }
}
