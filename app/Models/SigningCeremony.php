<?php

namespace App\Models;

use App\Models\Traits\FixesSqlServerDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SigningCeremony extends Model
{
    use FixesSqlServerDates, HasFactory;

    public const STATE_PREPARING = 'preparing';

    public const STATE_AWAITING_USER_SIGNATURE = 'awaiting_user_signature';

    public const STATE_USER_SIGNED = 'user_signed';

    public const STATE_FAILED = 'failed';

    public const STATE_SEALED = 'sealed';

    protected $fillable = [
        'uuid', 'evidence_uuid', 'document_id', 'document_version_id', 'intended_actor_id',
        'intended_role', 'delegation_id', 'otp_challenge_id', 'session_id_hash', 'nonce_hash',
        'manifest_draft_hash', 'candidate_pdf_hash', 'candidate_pdf_size', 'candidate_pdf_path',
        'reserved_number', 'qr_token', 'state', 'active_key', 'idempotency_key',
        'reauthenticated_at', 'authorization_result', 'expires_at', 'prepared_at',
        'consumed_at', 'failed_at', 'sealed_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'document_id' => 'integer', 'document_version_id' => 'integer',
            'intended_actor_id' => 'integer', 'delegation_id' => 'integer',
            'otp_challenge_id' => 'integer', 'candidate_pdf_size' => 'integer',
            'authorization_result' => 'boolean', 'reauthenticated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime', 'prepared_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime',
            'sealed_at' => 'immutable_datetime',
        ];
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function intendedActor()
    {
        return $this->belongsTo(User::class, 'intended_actor_id');
    }

    public function delegation()
    {
        return $this->belongsTo(Delegation::class);
    }

    public function otpChallenge()
    {
        return $this->belongsTo(SignatureOtpChallenge::class);
    }

    public function evidence()
    {
        return $this->hasOne(SignatureEvidence::class);
    }
}
