<?php

namespace App\Services;

use App\Contracts\ImmutableEvidenceStore;
use App\Support\StorageReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use LogicException;

class TestingImmutableEvidenceStore implements ImmutableEvidenceStore
{
    public function __construct()
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Adapter immutable lokal hanya boleh digunakan oleh automated test.');
        }
    }

    public function put(string $objectKey, string $bytes, array $metadata = []): StorageReceipt
    {
        $this->assertSafeKey($objectKey);
        $path = "immutable-test/{$objectKey}";
        $checksum = hash('sha256', $bytes);
        if (Storage::disk('local')->exists($path)) {
            $stored = Storage::disk('local')->get($path);
            if (! hash_equals(hash('sha256', $stored), $checksum)) {
                throw new LogicException('Immutable evidence tidak boleh ditimpa.');
            }
        } else {
            Storage::disk('local')->put($path, $bytes);
        }
        $readBack = Storage::disk('local')->get($path);
        if (! hash_equals($checksum, hash('sha256', $readBack))) {
            throw new LogicException('Read-after-write immutable evidence gagal.');
        }
        $now = CarbonImmutable::now('UTC');

        return new StorageReceipt(
            'testing-local-write-once', 'automated-tests', $objectKey, $checksum,
            $checksum, strlen($bytes), 'compliance', $now->addDays((int) config('tte.immutable.retention_days')), $now,
        );
    }

    public function read(string $objectKey, string $versionId): string
    {
        $this->assertSafeKey($objectKey);
        $bytes = Storage::disk('local')->get("immutable-test/{$objectKey}");
        if (! hash_equals($versionId, hash('sha256', $bytes))) {
            throw new LogicException('Versi immutable evidence tidak cocok.');
        }

        return $bytes;
    }

    public function exists(string $objectKey, string $versionId): bool
    {
        try {
            return hash_equals($versionId, hash('sha256', $this->read($objectKey, $versionId)));
        } catch (\Throwable) {
            return false;
        }
    }

    public function descriptor(): array
    {
        return ['provider' => 'testing-local-write-once', 'bucket' => 'automated-tests', 'retention_mode' => 'compliance'];
    }

    private function assertSafeKey(string $objectKey): void
    {
        if ($objectKey === '' || str_starts_with($objectKey, '/') || str_contains($objectKey, '..')) {
            throw new LogicException('Object key immutable tidak aman.');
        }
    }
}
