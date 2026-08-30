<?php

namespace App\Services;

use App\Contracts\ImmutableEvidenceStore;
use App\Contracts\SigningKeyRegistry;
use App\Models\AuditCheckpoint;
use App\Models\SignatureEvidence;
use App\Support\SigningKeyDescriptor;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

class EvidenceVerificationService
{
    public function __construct(
        private readonly CanonicalJson $canonicalJson,
        private readonly SigningKeyRegistry $keys,
        private readonly ?ImmutableEvidenceStore $immutableStore = null,
        private readonly ?AuditChainVerifier $auditChainVerifier = null,
    ) {}

    public function verifyEvidence(SignatureEvidence $evidence): array
    {
        $key = $evidence->signing_key_id ? $this->keys->find($evidence->signing_key_id) : null;
        $pdfPath = $evidence->pdf_path && Storage::disk('local')->exists($evidence->pdf_path)
            ? Storage::disk('local')->path($evidence->pdf_path)
            : null;
        try {
            $manifest = json_decode($evidence->canonical_manifest, true, flags: JSON_THROW_ON_ERROR);
            $canonicalManifest = hash_equals($evidence->canonical_manifest, $this->canonicalJson->encode($manifest));
        } catch (Throwable) {
            $manifest = [];
            $canonicalManifest = false;
        }
        $bundlePath = $evidence->bundle_path && Storage::disk('local')->exists($evidence->bundle_path)
            ? Storage::disk('local')->path($evidence->bundle_path)
            : null;
        $checks = [
            'canonical_manifest' => $canonicalManifest,
            'manifest_hash' => hash_equals($evidence->manifest_hash, hash('sha256', $evidence->canonical_manifest)),
            'pdf_hash' => $pdfPath ? hash_equals($evidence->pdf_hash, hash_file('sha256', $pdfPath)) : false,
            'manifest_file_binding' => isset($manifest['file']['sha256'], $manifest['file']['size'])
                && hash_equals($evidence->pdf_hash, $manifest['file']['sha256'])
                && $evidence->pdf_size === (int) $manifest['file']['size'],
            'otp_receipt_binding' => isset($manifest['otp_receipt'])
                && hash_equals($this->canonicalJson->encode($evidence->otp_receipt), $this->canonicalJson->encode($manifest['otp_receipt'])),
            'key_known' => $key !== null,
            'key_status' => $key ? in_array($key->status, ['active', 'retired'], true) : false,
            'key_manifest_binding' => $key && isset($manifest['institution_seal']['key_id'], $manifest['institution_seal']['key_fingerprint'])
                ? hash_equals($key->keyId, $manifest['institution_seal']['key_id'])
                    && hash_equals($key->fingerprint, $manifest['institution_seal']['key_fingerprint'])
                : false,
            'institution_signature' => $key ? $this->verifySignature($evidence->canonical_manifest, $evidence->institution_signature, $key) : false,
            'bundle_hash' => $bundlePath && $evidence->bundle_hash
                ? hash_equals($evidence->bundle_hash, hash_file('sha256', $bundlePath))
                : false,
        ];
        $auditCheckpoint = isset($manifest['audit_receipt']['checkpoint_id'])
            ? AuditCheckpoint::where('uuid', $manifest['audit_receipt']['checkpoint_id'])->first()
            : null;
        $checkpointKey = $auditCheckpoint ? $this->keys->find($auditCheckpoint->signing_key_id) : null;
        $checks['audit_chain'] = isset($manifest['audit_receipt']['event_hash'])
            && ($this->auditChainVerifier ?? app(AuditChainVerifier::class))
                ->verify($manifest['audit_receipt']['stream_id'] ?? 'global')['valid'];
        $checks['audit_checkpoint'] = $auditCheckpoint && $checkpointKey
            && hash_equals($auditCheckpoint->checkpoint_hash, hash('sha256', $auditCheckpoint->canonical_checkpoint))
            && hash_equals($manifest['audit_receipt']['checkpoint_hash'], $auditCheckpoint->checkpoint_hash)
            && $this->verifySignature($auditCheckpoint->canonical_checkpoint, $auditCheckpoint->signature, $checkpointKey);
        $copies = $evidence->storageCopies()->get();
        $checks['immutable_storage'] = $copies->count() >= 6 && $copies->every(function ($copy): bool {
            try {
                $bytes = ($this->immutableStore ?? app(ImmutableEvidenceStore::class))
                    ->read($copy->object_key, $copy->object_version_id);

                return hash_equals($copy->checksum, hash('sha256', $bytes))
                    && $copy->size === strlen($bytes)
                    && $copy->state === 'verified_after_write';
            } catch (Throwable) {
                return false;
            }
        });

        $administrativeStatus = $evidence->statusEvents()->latest('occurred_at')->value('status') ?? 'valid';

        return [
            'valid' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'key_status' => $key?->status ?? 'unknown',
            'administrative_status' => $administrativeStatus,
        ];
    }

    /** @param array<string,mixed>|null $trustedKey */
    public function verifyBundle(string $path, ?array $trustedKey = null): array
    {
        $checks = [
            'archive' => false, 'required_entries' => false, 'canonical_manifest' => false,
            'manifest_hash' => false, 'pdf_hash' => false, 'receipt_binding' => false,
            'institution_signature' => false, 'trusted_key' => false,
        ];
        try {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                return ['valid' => false, 'checks' => $checks, 'error' => 'archive_unreadable'];
            }
            $checks['archive'] = true;
            $required = ['document.pdf', 'evidence-manifest.json', 'institution-signature.json', 'otp-receipt.json', 'public-key.json'];
            $contents = [];
            foreach ($required as $entry) {
                $value = $zip->getFromName($entry);
                if (! is_string($value)) {
                    $zip->close();

                    return ['valid' => false, 'checks' => $checks, 'error' => "missing_{$entry}"];
                }
                $contents[$entry] = $value;
            }
            $zip->close();
            $checks['required_entries'] = true;

            $manifest = json_decode($contents['evidence-manifest.json'], true, flags: JSON_THROW_ON_ERROR);
            $signature = json_decode($contents['institution-signature.json'], true, flags: JSON_THROW_ON_ERROR);
            $receipt = json_decode($contents['otp-receipt.json'], true, flags: JSON_THROW_ON_ERROR);
            $embeddedKey = json_decode($contents['public-key.json'], true, flags: JSON_THROW_ON_ERROR);
            $checks['canonical_manifest'] = hash_equals($contents['evidence-manifest.json'], $this->canonicalJson->encode($manifest));
            $manifestHash = hash('sha256', $contents['evidence-manifest.json']);
            $checks['manifest_hash'] = isset($signature['manifest_hash']) && hash_equals($signature['manifest_hash'], $manifestHash);
            $checks['pdf_hash'] = isset($manifest['file']['sha256']) && hash_equals($manifest['file']['sha256'], hash('sha256', $contents['document.pdf']));
            $checks['receipt_binding'] = isset($manifest['otp_receipt'])
                && hash_equals($this->canonicalJson->encode($manifest['otp_receipt']), $this->canonicalJson->encode($receipt));

            $key = $this->descriptorFromArray($embeddedKey);
            $checks['institution_signature'] = isset($signature['signature'], $signature['key_id'], $signature['key_fingerprint'])
                && hash_equals($key->keyId, $signature['key_id'])
                && hash_equals($key->fingerprint, $signature['key_fingerprint'])
                && $this->verifySignature($contents['evidence-manifest.json'], $signature['signature'], $key);
            if ($trustedKey) {
                $trusted = $this->descriptorFromArray($trustedKey);
                $checks['trusted_key'] = hash_equals($trusted->keyId, $key->keyId)
                    && hash_equals($trusted->fingerprint, $key->fingerprint)
                    && hash_equals($trusted->publicKey, $key->publicKey);
            }

            return ['valid' => ! in_array(false, $checks, true), 'checks' => $checks, 'evidence_id' => $manifest['evidence_id'] ?? null];
        } catch (Throwable $exception) {
            return ['valid' => false, 'checks' => $checks, 'error' => 'verification_error'];
        }
    }

    private function verifySignature(string $message, ?string $signature, SigningKeyDescriptor $key): bool
    {
        if ($key->algorithm !== 'Ed25519' || ! is_string($signature)) {
            return false;
        }
        $signatureBytes = base64_decode($signature, true);
        $publicKey = base64_decode($key->publicKey, true);
        if ($signatureBytes === false || $publicKey === false || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signatureBytes, $message, $publicKey);
    }

    /** @param array<string,mixed> $key */
    private function descriptorFromArray(array $key): SigningKeyDescriptor
    {
        foreach (['key_id', 'algorithm', 'public_key', 'fingerprint', 'status'] as $field) {
            if (! isset($key[$field]) || ! is_string($key[$field])) {
                throw new \InvalidArgumentException('Format public key tidak valid.');
            }
        }
        $public = base64_decode($key['public_key'], true);
        if ($public === false || ! hash_equals($key['fingerprint'], hash('sha256', $public))) {
            throw new \InvalidArgumentException('Fingerprint public key tidak valid.');
        }

        return new SigningKeyDescriptor($key['key_id'], $key['algorithm'], $key['public_key'], $key['fingerprint'], $key['status']);
    }
}
