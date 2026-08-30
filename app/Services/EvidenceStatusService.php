<?php

namespace App\Services;

use App\Models\EvidenceStatusEvent;
use App\Models\SignatureEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvidenceStatusService
{
    public function __construct(
        private readonly AuditChainWriter $audit,
        private readonly AuditCheckpointService $checkpoints,
        private readonly SecurityEventReporter $events,
    ) {}

    public function record(
        SignatureEvidence $evidence,
        string $status,
        string $reason,
        string $externalReference,
        ?string $relatedEvidenceUuid = null,
        ?int $actorId = null,
    ): EvidenceStatusEvent {
        if (! in_array($status, ['revoked', 'superseded'], true) || trim($reason) === '' || trim($externalReference) === '') {
            throw new \InvalidArgumentException('Status, alasan, dan referensi insiden/change wajib valid.');
        }
        if ($status === 'superseded' && ! $relatedEvidenceUuid) {
            throw new \InvalidArgumentException('Evidence pengganti wajib dicantumkan untuk status superseded.');
        }
        if ($existing = EvidenceStatusEvent::where('signature_evidence_id', $evidence->id)
            ->where('status', $status)->where('external_reference', $externalReference)->first()) {
            return $existing;
        }
        if (EvidenceStatusEvent::where('signature_evidence_id', $evidence->id)->where('status', 'revoked')->exists()) {
            throw new \LogicException('Evidence yang dicabut tidak boleh diaktifkan atau ditulis ulang.');
        }
        $auditEvent = $this->audit->append(
            'evidence_administrative_status_recorded',
            [
                'evidence_uuid' => $evidence->uuid,
                'external_reference' => $externalReference,
                'reason' => $reason,
                'related_evidence_uuid' => $relatedEvidenceUuid,
                'status' => $status,
            ],
            $actorId,
            SignatureEvidence::class,
            (string) $evidence->id,
            idempotencyKey: "evidence-status|{$evidence->uuid}|{$status}|{$externalReference}",
        );
        $checkpoint = $this->checkpoints->create();

        $event = DB::transaction(fn () => EvidenceStatusEvent::firstOrCreate(
            ['signature_evidence_id' => $evidence->id, 'status' => $status, 'external_reference' => $externalReference],
            [
                'uuid' => (string) Str::uuid(),
                'reason' => $reason,
                'related_evidence_uuid' => $relatedEvidenceUuid,
                'actor_id' => $actorId,
                'audit_chain_event_id' => $auditEvent->id,
                'audit_checkpoint_id' => $checkpoint->id,
                'occurred_at' => now()->utc(),
            ],
        ), 3);
        $this->events->report('evidence_administrative_status_recorded', [
            'actor_id' => $actorId,
            'evidence_id' => $evidence->uuid,
            'external_reference' => $externalReference,
            'status' => $status,
        ], 'warning');

        return $event;
    }
}
