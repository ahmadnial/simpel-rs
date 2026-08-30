<?php

namespace App\Services;

use App\Contracts\ImmutableEvidenceStore;
use App\Support\StorageReceipt;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use LogicException;

class MinioImmutableEvidenceStore implements ImmutableEvidenceStore
{
    private S3Client $client;

    public function __construct(?S3Client $client = null)
    {
        $this->client = $client ?? new S3Client([
            'version' => 'latest',
            'region' => config('tte.providers.minio.region'),
            'endpoint' => config('tte.providers.minio.endpoint'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => config('tte.providers.minio.access_key'),
                'secret' => config('tte.providers.minio.secret_key'),
            ],
            'http' => ['connect_timeout' => 3, 'timeout' => 20],
        ]);
    }

    public function put(string $objectKey, string $bytes, array $metadata = []): StorageReceipt
    {
        $this->assertSafeKey($objectKey);
        $checksum = hash('sha256', $bytes);
        $retainUntil = CarbonImmutable::now('UTC')->addDays((int) config('tte.immutable.retention_days'));
        $result = $this->client->putObject([
            'Bucket' => $this->bucket(), 'Key' => $objectKey, 'Body' => $bytes,
            'ContentType' => 'application/octet-stream',
            'Metadata' => array_merge($metadata, ['sha256' => $checksum]),
            'ObjectLockMode' => 'COMPLIANCE', 'ObjectLockRetainUntilDate' => $retainUntil->toDateTimeImmutable(),
        ]);
        $versionId = (string) ($result['VersionId'] ?? '');
        if ($versionId === '') {
            throw new LogicException('MinIO tidak mengembalikan VersionId; Object Lock gagal dibuktikan.');
        }
        $verifiedAt = CarbonImmutable::now('UTC');

        return new StorageReceipt('minio-object-lock', $this->bucket(), $objectKey, $versionId,
            $checksum, strlen($bytes), 'compliance', $retainUntil, $verifiedAt);
    }

    public function read(string $objectKey, string $versionId): string
    {
        $this->assertSafeKey($objectKey);
        $result = $this->client->getObject(['Bucket' => $this->bucket(), 'Key' => $objectKey, 'VersionId' => $versionId]);

        return (string) $result['Body'];
    }

    public function exists(string $objectKey, string $versionId): bool
    {
        try {
            $this->client->headObject(['Bucket' => $this->bucket(), 'Key' => $objectKey, 'VersionId' => $versionId]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function descriptor(): array
    {
        return ['provider' => 'minio-object-lock', 'bucket' => $this->bucket(), 'retention_mode' => 'compliance'];
    }

    private function bucket(): string { return (string) config('tte.providers.minio.bucket'); }

    private function assertSafeKey(string $key): void
    {
        if ($key === '' || str_starts_with($key, '/') || str_contains($key, '..')) {
            throw new LogicException('Object key immutable tidak aman.');
        }
    }
}
