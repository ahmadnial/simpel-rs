<?php

namespace App\Services;

use App\Contracts\ImmutableEvidenceStore;
use App\Models\SignatureEvidence;
use App\Support\StorageReceipt;

class EvidenceStorageService
{
    public function __construct(
        private readonly ImmutableEvidenceStore $store,
        private readonly SecurityEventReporter $securityEvents,
    ) {}

    /** @param array<string,string> $artifacts @return array<string,StorageReceipt> */
    public function storeAndVerify(string $evidenceUuid, array $artifacts): array
    {
        $receipts = [];
        foreach ($artifacts as $type => $bytes) {
            $key = "evidence/{$evidenceUuid}/{$type}";
            $receipt = $this->store->put($key, $bytes, ['evidence_uuid' => $evidenceUuid, 'artifact_type' => $type]);
            $readBack = $this->store->read($receipt->objectKey, $receipt->versionId);
            if (! hash_equals($receipt->checksum, hash('sha256', $readBack)) || $receipt->size !== strlen($readBack)) {
                $this->securityEvents->report('worm_readback_mismatch', [
                    'artifact_type' => $type,
                    'evidence_id' => $evidenceUuid,
                    'object_key' => $receipt->objectKey,
                ], 'critical');
                throw new \LogicException("Rekonsiliasi immutable artifact {$type} gagal.");
            }
            $receipts[$type] = $receipt;
        }

        return $receipts;
    }

    /** @return array{valid:bool,checked:int,errors:array<int,string>} */
    public function reconcile(SignatureEvidence $evidence): array
    {
        $errors = [];
        $copies = $evidence->storageCopies()->get();
        foreach ($copies as $copy) {
            try {
                $bytes = $this->store->read($copy->object_key, $copy->object_version_id);
                if (! hash_equals($copy->checksum, hash('sha256', $bytes)) || $copy->size !== strlen($bytes)) {
                    $errors[] = $copy->artifact_type.':checksum_or_size_mismatch';
                }
            } catch (\Throwable) {
                $errors[] = $copy->artifact_type.':unreadable';
            }
        }
        if ($copies->count() < 6) {
            $errors[] = 'storage_copy_gap';
        }
        if ($errors !== []) {
            $this->securityEvents->report('evidence_reconciliation_failed', [
                'evidence_id' => $evidence->uuid,
                'errors' => $errors,
            ], 'critical');
        }

        return ['valid' => $errors === [], 'checked' => $copies->count(), 'errors' => $errors];
    }
}
