<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SigningOutboxMessage extends Model
{
    protected $fillable = [
        'uuid', 'signing_ceremony_id', 'type', 'idempotency_key', 'payload', 'state',
        'attempt_count', 'available_at', 'processed_at', 'failed_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'signing_ceremony_id' => 'integer', 'payload' => 'array', 'attempt_count' => 'integer',
            'available_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function ceremony()
    {
        return $this->belongsTo(SigningCeremony::class, 'signing_ceremony_id');
    }
}
