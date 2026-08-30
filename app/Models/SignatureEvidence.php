<?php

namespace App\Models;

use App\Models\Traits\FixesSqlServerDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SignatureEvidence extends Model
{
    use FixesSqlServerDates, HasFactory;

    protected $table = 'signature_evidence';

    protected $fillable = [
        'uuid', 'schema_version', 'assurance_profile', 'document_id', 'document_version_id',
        'signing_ceremony_id', 'otp_challenge_id', 'pdf_hash', 'pdf_size', 'pdf_path',
        'canonical_manifest', 'manifest_hash', 'document_snapshot', 'signer_snapshot',
        'workflow_snapshot', 'delegation_snapshot', 'otp_receipt', 'state', 'sealed_at',
        'signature_algorithm', 'signing_key_id', 'signing_key_fingerprint',
        'institution_signature', 'bundle_path', 'bundle_hash', 'bundle_size',
    ];

    protected function casts(): array
    {
        return [
            'document_id' => 'integer', 'document_version_id' => 'integer',
            'signing_ceremony_id' => 'integer', 'otp_challenge_id' => 'integer',
            'pdf_size' => 'integer', 'document_snapshot' => 'array', 'signer_snapshot' => 'array',
            'workflow_snapshot' => 'array', 'delegation_snapshot' => 'array',
            'otp_receipt' => 'array', 'sealed_at' => 'immutable_datetime',
            'bundle_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SignatureEvidence $evidence): void {
            $immutable = [
                'uuid', 'schema_version', 'assurance_profile', 'document_id', 'document_version_id',
                'signing_ceremony_id', 'otp_challenge_id', 'pdf_hash', 'pdf_size', 'pdf_path',
                'canonical_manifest', 'manifest_hash', 'document_snapshot', 'signer_snapshot',
                'workflow_snapshot', 'delegation_snapshot', 'otp_receipt',
                'signature_algorithm', 'signing_key_id', 'signing_key_fingerprint',
                'institution_signature', 'bundle_path', 'bundle_hash', 'bundle_size',
            ];
            if ($evidence->isDirty($immutable)) {
                throw new \LogicException('Artefak inti signature evidence bersifat immutable.');
            }
        });
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
        return $this->belongsTo(SigningCeremony::class, 'signing_ceremony_id');
    }

    public function otpChallenge()
    {
        return $this->belongsTo(SignatureOtpChallenge::class);
    }

    public function storageCopies()
    {
        return $this->hasMany(EvidenceStorageCopy::class);
    }

    public function statusEvents()
    {
        return $this->hasMany(EvidenceStatusEvent::class);
    }
}
