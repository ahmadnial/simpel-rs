<?php

namespace App\Support;

use Carbon\CarbonImmutable;

readonly class StorageReceipt
{
    public function __construct(
        public string $provider,
        public string $bucket,
        public string $objectKey,
        public string $versionId,
        public string $checksum,
        public int $size,
        public string $retentionMode,
        public CarbonImmutable $retentionUntil,
        public CarbonImmutable $verifiedAt,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'bucket' => $this->bucket,
            'object_key' => $this->objectKey,
            'version_id' => $this->versionId,
            'checksum' => $this->checksum,
            'size' => $this->size,
            'retention_mode' => $this->retentionMode,
            'retention_until' => $this->retentionUntil->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
            'verified_at' => $this->verifiedAt->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
        ];
    }
}
