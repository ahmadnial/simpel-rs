<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SigningKey extends Model
{
    protected $fillable = [
        'key_id', 'algorithm', 'purpose', 'provider_reference', 'public_key', 'fingerprint',
        'status', 'activated_at', 'retired_at', 'revoked_at', 'compromised_at', 'reason',
        'policy_version', 'approval_references',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'immutable_datetime', 'retired_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime', 'compromised_at' => 'immutable_datetime',
            'approval_references' => 'array',
        ];
    }
}
