<?php

namespace App\Contracts;

use App\Support\StorageReceipt;

interface ImmutableEvidenceStore
{
    /** @param array<string, string> $metadata */
    public function put(string $objectKey, string $bytes, array $metadata = []): StorageReceipt;

    public function read(string $objectKey, string $versionId): string;

    public function exists(string $objectKey, string $versionId): bool;

    /** @return array{provider:string,bucket:string,retention_mode:string} */
    public function descriptor(): array;
}
