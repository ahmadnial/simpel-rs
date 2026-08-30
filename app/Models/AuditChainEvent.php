<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditChainEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'stream_id', 'sequence', 'previous_event_hash', 'payload_hash', 'event_hash',
        'event_type', 'actor_id', 'target_type', 'target_id', 'correlation_id', 'idempotency_key',
        'canonical_payload', 'metadata', 'application_time', 'database_time', 'result',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer', 'actor_id' => 'integer', 'metadata' => 'array',
            'application_time' => 'immutable_datetime', 'database_time' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit chain event bersifat append-only.'));
        static::deleting(fn () => throw new LogicException('Audit chain event tidak boleh dihapus.'));
    }
}
