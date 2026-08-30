<?php

namespace Tests\Feature;

use App\Models\SigningKey;
use App\Services\MinioImmutableEvidenceStore;
use App\Services\OpenBaoEvidenceSigner;
use App\Services\RuntimeOtpSecretProvider;
use App\Services\HmacOtpVerifier;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ProductionEvidenceAdaptersTest extends TestCase
{
    use RefreshDatabase;

    public function test_openbao_adapter_registers_public_key_and_returns_valid_signature_envelope(): void
    {
        $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $signature = random_bytes(SODIUM_CRYPTO_SIGN_BYTES);
        config()->set('tte.providers.openbao', [
            'address' => 'http://127.0.0.1:8200',
            'token' => str_repeat('t', 32),
            'transit_key' => 'simpel-rs-evidence',
            'timeout_seconds' => 2,
        ]);
        Http::fake(function ($request) use ($publicKey, $signature) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => [
                    'latest_version' => 1,
                    'keys' => ['1' => ['public_key' => base64_encode($publicKey)]],
                ]]);
            }

            return Http::response(['data' => ['signature' => 'vault:v1:'.base64_encode($signature)]]);
        });

        $result = (new OpenBaoEvidenceSigner)->sign('{"document":"sha256"}');

        $this->assertSame('openbao:simpel-rs-evidence:v1', $result->key->keyId);
        $this->assertSame(base64_encode($signature), $result->signature);
        $this->assertDatabaseHas('signing_keys', [
            'key_id' => 'openbao:simpel-rs-evidence:v1',
            'fingerprint' => hash('sha256', $publicKey),
            'status' => 'active',
        ]);
    }

    public function test_openbao_adapter_fails_closed_when_token_is_missing(): void
    {
        config()->set('tte.providers.openbao.token', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token OpenBao belum dikonfigurasi.');

        (new OpenBaoEvidenceSigner)->activeKey();
    }

    public function test_minio_adapter_requires_versioned_write_and_reads_exact_version(): void
    {
        config()->set('tte.providers.minio.bucket', 'simpel-rs-evidence');
        config()->set('tte.immutable.retention_days', 3650);
        $handler = new MockHandler;
        $handler->append(
            new Result(['VersionId' => 'version-123']),
            new Result(['Body' => Utils::streamFor('immutable-bytes')]),
            new Result([]),
        );
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'http://127.0.0.1:9000',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'test', 'secret' => 'test-secret'],
            'handler' => $handler,
        ]);
        $store = new MinioImmutableEvidenceStore($client);

        $receipt = $store->put('evidence/100/test.bin', 'immutable-bytes', ['sha256' => 'cannot-override']);

        $this->assertSame('version-123', $receipt->versionId);
        $this->assertSame(hash('sha256', 'immutable-bytes'), $receipt->checksum);
        $this->assertSame('compliance', $receipt->retentionMode);
        $this->assertSame('immutable-bytes', $store->read('evidence/100/test.bin', 'version-123'));
        $this->assertTrue($store->exists('evidence/100/test.bin', 'version-123'));
    }

    public function test_minio_adapter_rejects_unversioned_write_response(): void
    {
        config()->set('tte.providers.minio.bucket', 'simpel-rs-evidence');
        $handler = new MockHandler;
        $handler->append(new Result([]));
        $client = new S3Client([
            'version' => 'latest', 'region' => 'us-east-1',
            'credentials' => ['key' => 'test', 'secret' => 'test-secret'],
            'handler' => $handler,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Object Lock gagal dibuktikan.');

        (new MinioImmutableEvidenceStore($client))->put('evidence/test.bin', 'bytes');
    }

    public function test_runtime_otp_provider_uses_cached_configuration_values(): void
    {
        config()->set('tte.otp.secret', str_repeat('a', 32));
        config()->set('tte.otp.destination_secret', str_repeat('b', 32));

        $provider = new RuntimeOtpSecretProvider;

        $this->assertSame(str_repeat('a', 32), $provider->otpKey());
        $this->assertSame(str_repeat('b', 32), $provider->destinationKey());
    }

    public function test_otp_hmac_is_stable_when_sql_server_uppercases_uniqueidentifier(): void
    {
        config()->set('tte.otp.secret', str_repeat('a', 32));
        $verifier = new HmacOtpVerifier(new RuntimeOtpSecretProvider);
        $uuid = '8bd69098-8fa1-4bd0-b455-45e0244d56d6';
        $stored = $verifier->make($uuid, '01234567');

        $this->assertTrue($verifier->verify(strtoupper($uuid), '01234567', $stored));
        $this->assertFalse($verifier->verify(strtoupper($uuid), '01234568', $stored));
    }
}
