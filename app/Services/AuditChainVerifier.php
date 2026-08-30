<?php

namespace App\Services;

use App\Models\AuditChainEvent;
use App\Models\AuditChainStream;

class AuditChainVerifier
{
    public function __construct(private readonly CanonicalJson $canonicalJson) {}

    /** @return array{valid:bool,event_count:int,errors:array<int,string>,last_event_hash:string} */
    public function verify(string $streamId = 'global'): array
    {
        $previous = AuditChainWriter::GENESIS_HASH;
        $expectedSequence = 1;
        $errors = [];
        $events = AuditChainEvent::where('stream_id', $streamId)->orderBy('sequence')->get();
        foreach ($events as $event) {
            if ($event->sequence !== $expectedSequence) {
                $errors[] = "sequence_gap_at_{$expectedSequence}";
            }
            if (! hash_equals($previous, $event->previous_event_hash)) {
                $errors[] = "previous_hash_mismatch_at_{$event->sequence}";
            }
            if (! hash_equals($event->payload_hash, hash('sha256', $event->canonical_payload))) {
                $errors[] = "payload_hash_mismatch_at_{$event->sequence}";
            }
            $hashInput = $this->canonicalJson->encode([
                'actor_id' => $event->actor_id,
                'application_time' => $event->application_time->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
                'correlation_id' => strtolower($event->correlation_id),
                'database_time' => $event->database_time->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
                'event_type' => $event->event_type,
                'metadata' => $event->metadata ?? [],
                'payload_hash' => $event->payload_hash,
                'previous_event_hash' => $event->previous_event_hash,
                'result' => $event->result,
                'sequence' => $event->sequence,
                'stream_id' => $event->stream_id,
                'target_id' => $event->target_id,
                'target_type' => $event->target_type,
            ]);
            if (! hash_equals($event->event_hash, hash('sha256', $hashInput.$event->previous_event_hash))) {
                $errors[] = "event_hash_mismatch_at_{$event->sequence}";
            }
            $previous = $event->event_hash;
            $expectedSequence = $event->sequence + 1;
        }
        $stream = AuditChainStream::find($streamId);
        if (! $stream || $stream->last_sequence !== $events->count() || ! hash_equals($stream->last_event_hash, $previous)) {
            $errors[] = 'stream_head_mismatch';
        }

        return ['valid' => $errors === [], 'event_count' => $events->count(), 'errors' => $errors, 'last_event_hash' => $previous];
    }
}
