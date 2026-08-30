<?php

namespace App\Services;

use App\Contracts\ImmutableEvidenceStore;
use App\Support\StorageReceipt;
use RuntimeException;

class UnavailableImmutableEvidenceStore implements ImmutableEvidenceStore
{
    public function put(string $objectKey, string $bytes, array $metadata = []): StorageReceipt
    {
        throw new RuntimeException('WORM evidence provider production belum dikonfigurasi; signing dihentikan fail-closed.');
    }

    public function read(string $objectKey, string $versionId): string
    {
        throw new RuntimeException('WORM evidence provider production belum dikonfigurasi.');
    }

    public function exists(string $objectKey, string $versionId): bool
    {
        return false;
    }

    public function descriptor(): array
    {
        return ['provider' => 'unavailable', 'bucket' => 'unconfigured', 'retention_mode' => 'unconfigured'];
    }
}
