<?php

namespace App\Services;

use App\Contracts\EvidenceSigner;
use App\Contracts\ImmutableEvidenceStore;
use App\Models\AuditChainEvent;
use App\Models\AuditCheckpoint;
use Illuminate\Support\Str;

class AuditCheckpointService
{
    public function __construct(
        private readonly CanonicalJson $canonicalJson,
        private readonly EvidenceSigner $signer,
        private readonly ImmutableEvidenceStore $store,
        private readonly AuditChainVerifier $verifier,
    ) {}

    public function create(string $streamId = 'global'): AuditCheckpoint
    {
        $verification = $this->verifier->verify($streamId);
        if (! $verification['valid'] || $verification['event_count'] === 0) {
            throw new \LogicException('Audit chain tidak valid atau kosong; checkpoint ditolak.');
        }
        $last = AuditChainEvent::where('stream_id', $streamId)->orderByDesc('sequence')->firstOrFail();
        if ($existing = AuditCheckpoint::where('stream_id', $streamId)->where('sequence_end', $last->sequence)->first()) {
            return $existing;
        }
        $uuid = (string) Str::uuid();
        $canonical = $this->canonicalJson->encode([
            'checkpoint_id' => $uuid,
            'event_count' => $verification['event_count'],
            'last_event_hash' => $last->event_hash,
            'schema_version' => '1.0',
            'sequence_end' => $last->sequence,
            'sequence_start' => 1,
            'stream_id' => $streamId,
        ]);
        $signature = $this->signer->sign($canonical);
        $envelope = $this->canonicalJson->encode([
            'checkpoint' => json_decode($canonical, true, flags: JSON_THROW_ON_ERROR),
            'signature' => $signature->signature,
            'signing_key' => $signature->key->toArray(),
        ]);
        $receipt = $this->store->put("audit-checkpoints/{$uuid}.json", $envelope, ['type' => 'audit_checkpoint']);

        return AuditCheckpoint::create([
            'uuid' => $uuid,
            'stream_id' => $streamId,
            'sequence_start' => 1,
            'sequence_end' => $last->sequence,
            'event_count' => $verification['event_count'],
            'last_event_hash' => $last->event_hash,
            'checkpoint_hash' => hash('sha256', $canonical),
            'canonical_checkpoint' => $canonical,
            'signature_algorithm' => $signature->key->algorithm,
            'signing_key_id' => $signature->key->keyId,
            'signing_key_fingerprint' => $signature->key->fingerprint,
            'signature' => $signature->signature,
            'storage_receipt' => $receipt->toArray(),
        ]);
    }
}
