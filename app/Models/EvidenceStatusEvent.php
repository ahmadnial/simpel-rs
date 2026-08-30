<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EvidenceStatusEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid', 'signature_evidence_id', 'status', 'reason', 'external_reference',
        'related_evidence_uuid', 'actor_id', 'audit_chain_event_id', 'audit_checkpoint_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'signature_evidence_id' => 'integer', 'actor_id' => 'integer',
            'audit_chain_event_id' => 'integer', 'audit_checkpoint_id' => 'integer',
            'occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Status evidence bersifat append-only.'));
        static::deleting(fn () => throw new LogicException('Status evidence tidak boleh dihapus.'));
    }
}
