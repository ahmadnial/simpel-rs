<?php

namespace App\Services;

use App\Models\AuditChainEvent;
use App\Models\AuditChainStream;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditChainWriter
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly CanonicalJson $canonicalJson) {}

    /** @param array<string,mixed> $payload @param array<string,mixed> $metadata */
    public function append(
        string $eventType,
        array $payload,
        ?int $actorId = null,
        ?string $targetType = null,
        ?string $targetId = null,
        string $streamId = 'global',
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
        string $result = 'success',
        array $metadata = [],
    ): AuditChainEvent {
        $hasExplicitIdempotency = $idempotencyKey !== null;
        $normalizedIdempotency = hash('sha256', $idempotencyKey ?? random_bytes(32));

        return DB::transaction(function () use ($eventType, $payload, $actorId, $targetType, $targetId, $streamId, $correlationId, $normalizedIdempotency, $hasExplicitIdempotency, $result, $metadata) {
            $timestamp = now();
            if (DB::getDriverName() === 'sqlsrv') {
                DB::statement(
                    'IF NOT EXISTS (SELECT 1 FROM audit_chain_streams WITH (UPDLOCK, HOLDLOCK) WHERE stream_id = ?) '
                    .'INSERT INTO audit_chain_streams (stream_id, last_sequence, last_event_hash, created_at, updated_at) VALUES (?, 0, ?, ?, ?)',
                    [$streamId, $streamId, self::GENESIS_HASH, $timestamp, $timestamp],
                );
            } else {
                DB::table('audit_chain_streams')->insertOrIgnore([
                    'stream_id' => $streamId,
                    'last_sequence' => 0,
                    'last_event_hash' => self::GENESIS_HASH,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
            $stream = AuditChainStream::lockForUpdate()->findOrFail($streamId);
            if ($hasExplicitIdempotency) {
                $existing = AuditChainEvent::where('stream_id', $streamId)
                    ->where('idempotency_key', $normalizedIdempotency)->first();
                if ($existing) {
                    return $existing;
                }
            }
            $sequence = $stream->last_sequence + 1;
            $canonicalPayload = $this->canonicalJson->encode($payload);
            $payloadHash = hash('sha256', $canonicalPayload);
            $applicationTime = now()->utc()->startOfSecond();
            $databaseTime = DB::selectOne('SELECT CURRENT_TIMESTAMP AS database_utc')->database_utc;
            $databaseTime = CarbonImmutable::parse($databaseTime)->utc()->startOfSecond();
            // SQL Server uniqueidentifier membaca kembali UUID sebagai uppercase.
            // Hash selalu memakai bentuk lowercase agar byte kanonis lintas driver stabil.
            $correlationId = strtolower($correlationId ?? (string) Str::uuid());
            $hashInput = $this->canonicalJson->encode([
                'actor_id' => $actorId,
                'application_time' => $applicationTime->format('Y-m-d\\TH:i:s.u\\Z'),
                'correlation_id' => $correlationId,
                'database_time' => $databaseTime->format('Y-m-d\\TH:i:s.u\\Z'),
                'event_type' => $eventType,
                'metadata' => $metadata,
                'payload_hash' => $payloadHash,
                'previous_event_hash' => $stream->last_event_hash,
                'result' => $result,
                'sequence' => $sequence,
                'stream_id' => $streamId,
                'target_id' => $targetId,
                'target_type' => $targetType,
            ]);
            $event = AuditChainEvent::create([
                'stream_id' => $streamId,
                'sequence' => $sequence,
                'previous_event_hash' => $stream->last_event_hash,
                'payload_hash' => $payloadHash,
                'event_hash' => hash('sha256', $hashInput.$stream->last_event_hash),
                'event_type' => $eventType,
                'actor_id' => $actorId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'correlation_id' => $correlationId,
                'idempotency_key' => $normalizedIdempotency,
                'canonical_payload' => $canonicalPayload,
                'metadata' => $metadata,
                'application_time' => $applicationTime,
                'database_time' => $databaseTime,
                'result' => $result,
            ]);
            $stream->update(['last_sequence' => $sequence, 'last_event_hash' => $event->event_hash]);

            return $event;
        }, 5);
    }
}
