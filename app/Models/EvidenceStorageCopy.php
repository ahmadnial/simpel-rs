<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EvidenceStorageCopy extends Model
{
    protected $fillable = [
        'signature_evidence_id', 'evidence_uuid', 'artifact_type', 'storage_provider',
        'bucket_logical_id', 'object_key', 'object_version_id', 'checksum', 'size',
        'retention_mode', 'retention_until', 'verified_at', 'state',
    ];

    protected function casts(): array
    {
        return [
            'signature_evidence_id' => 'integer', 'size' => 'integer',
            'retention_until' => 'immutable_datetime', 'verified_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Storage receipt bersifat immutable.'));
        static::deleting(fn () => throw new LogicException('Storage receipt tidak boleh dihapus.'));
    }
}
