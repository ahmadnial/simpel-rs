<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditCheckpoint extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid', 'stream_id', 'sequence_start', 'sequence_end', 'event_count',
        'last_event_hash', 'checkpoint_hash', 'canonical_checkpoint', 'signature_algorithm',
        'signing_key_id', 'signing_key_fingerprint', 'signature', 'storage_receipt',
    ];

    protected function casts(): array
    {
        return [
            'sequence_start' => 'integer', 'sequence_end' => 'integer', 'event_count' => 'integer',
            'storage_receipt' => 'array', 'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit checkpoint bersifat immutable.'));
        static::deleting(fn () => throw new LogicException('Audit checkpoint tidak boleh dihapus.'));
    }
}
