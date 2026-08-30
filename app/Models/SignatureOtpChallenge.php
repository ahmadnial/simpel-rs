<?php

namespace App\Models;

use App\Models\Traits\FixesSqlServerDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SignatureOtpChallenge extends Model
{
    use FixesSqlServerDates, HasFactory;

    public const STATE_PENDING_SEND = 'pending_send';

    public const STATE_SENT = 'sent';

    public const STATE_CONSUMED = 'consumed';

    public const STATE_EXPIRED = 'expired';

    public const STATE_LOCKED = 'locked';

    public const STATE_REVOKED = 'revoked';

    public const STATE_SEND_FAILED = 'send_failed';

    protected $fillable = [
        'uuid', 'user_id', 'document_id', 'document_version_id', 'signing_ceremony_id',
        'pdf_hash', 'manifest_draft_hash', 'nonce_hash', 'session_id_hash', 'action',
        'policy_version', 'otp_verifier', 'masked_destination', 'destination_hash',
        'resend_generation', 'attempt_count', 'max_attempts', 'state', 'active_binding_key',
        'correlation_id', 'source_ip_hash', 'user_agent_hash', 'authorization_result',
        'reauthentication_age_seconds', 'requested_at', 'sent_at', 'expires_at',
        'verified_at', 'consumed_at', 'sealed_at', 'revoked_at', 'locked_at', 'failure_reason',
    ];

    protected $hidden = ['otp_verifier'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'document_id' => 'integer',
            'document_version_id' => 'integer',
            'resend_generation' => 'integer',
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'authorization_result' => 'boolean',
            'reauthentication_age_seconds' => 'integer',
            'requested_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'sealed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function ceremony()
    {
        return $this->hasOne(SigningCeremony::class, 'otp_challenge_id');
    }
}
