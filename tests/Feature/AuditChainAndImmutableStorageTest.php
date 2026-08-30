<?php

namespace Tests\Feature;

use App\Contracts\ImmutableEvidenceStore;
use App\Services\AuditChainVerifier;
use App\Services\AuditChainWriter;
use App\Services\AuditCheckpointService;
use App\Services\EvidenceStorageService;
use App\Services\UnavailableImmutableEvidenceStore;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class AuditChainAndImmutableStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_audit_chain_detects_payload_mutation(): void
    {
        $this->appendThreeEvents();
        DB::table('audit_chain_events')->where('sequence', 2)->update(['canonical_payload' => '{"forged":true}']);

        $result = app(AuditChainVerifier::class)->verify();
        $this->assertFalse($result['valid']);
        $this->assertContains('payload_hash_mismatch_at_2', $result['errors']);
    }

    public function test_audit_chain_detects_delete_gap(): void
    {
        $this->appendThreeEvents();
        DB::table('audit_chain_events')->where('sequence', 2)->delete();
        $deleted = app(AuditChainVerifier::class)->verify();
        $this->assertFalse($deleted['valid']);
        $this->assertContains('sequence_gap_at_2', $deleted['errors']);

    }

    public function test_audit_chain_detects_reorder_or_insertion_gap(): void
    {
        $this->appendThreeEvents();
        DB::table('audit_chain_events')->where('sequence', 2)->update(['sequence' => 9]);
        $reordered = app(AuditChainVerifier::class)->verify();
        $this->assertFalse($reordered['valid']);
        $this->assertTrue(collect($reordered['errors'])->contains(fn (string $error) => str_starts_with($error, 'sequence_gap_')));
    }

    public function test_model_guards_and_idempotency_enforce_append_only_contract(): void
    {
        $writer = app(AuditChainWriter::class);
        $first = $writer->append('test', ['value' => 1], idempotencyKey: 'same-operation');
        $retry = $writer->append('test', ['value' => 1], idempotencyKey: 'same-operation');
        $this->assertTrue($first->is($retry));
        $this->assertDatabaseCount('audit_chain_events', 1);

        $this->expectException(LogicException::class);
        $first->update(['result' => 'forged']);
    }

    public function test_verifier_normalizes_sql_server_uppercase_uniqueidentifier(): void
    {
        $event = app(AuditChainWriter::class)->append('sqlserver_uuid_case', ['value' => 1]);
        DB::table('audit_chain_events')->where('id', $event->id)->update([
            'correlation_id' => strtoupper($event->correlation_id),
        ]);

        $result = app(AuditChainVerifier::class)->verify();

        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }

    public function test_signed_checkpoint_is_stored_and_read_back_unchanged(): void
    {
        $this->appendThreeEvents();
        $checkpoint = app(AuditCheckpointService::class)->create();
        $receipt = $checkpoint->storage_receipt;
        $stored = app(ImmutableEvidenceStore::class)->read($receipt['object_key'], $receipt['version_id']);
        $envelope = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        $publicKey = base64_decode($envelope['signing_key']['public_key'], true);
        $signature = base64_decode($envelope['signature'], true);

        $this->assertSame($checkpoint->checkpoint_hash, hash('sha256', $checkpoint->canonical_checkpoint));
        $this->assertSame($receipt['checksum'], hash('sha256', $stored));
        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, $checkpoint->canonical_checkpoint, $publicKey));
        $this->assertTrue(app(AuditChainVerifier::class)->verify()['valid']);
    }

    public function test_immutable_store_rejects_overwrite_and_reconciliation_restores_exact_bytes(): void
    {
        $service = app(EvidenceStorageService::class);
        $uuid = 'restore-test-'.bin2hex(random_bytes(4));
        $artifacts = ['document.pdf' => "%PDF-1.7\nrestore", 'evidence-bundle.zip' => random_bytes(128)];
        $receipts = $service->storeAndVerify($uuid, $artifacts);

        foreach ($artifacts as $type => $expected) {
            $receipt = $receipts[$type];
            $restored = app(ImmutableEvidenceStore::class)->read($receipt->objectKey, $receipt->versionId);
            $this->assertSame($expected, $restored);
            $this->assertSame(hash('sha256', $expected), $receipt->checksum);
        }

        $this->expectException(LogicException::class);
        app(ImmutableEvidenceStore::class)->put("evidence/{$uuid}/document.pdf", 'forged');
    }

    public function test_production_adapter_fails_closed_without_worm_provider(): void
    {
        $this->expectException(RuntimeException::class);
        (new UnavailableImmutableEvidenceStore)->put('evidence/x/document.pdf', 'bytes');
    }

    public function test_clean_transactions_is_blocked_outside_local_and_testing(): void
    {
        $audit = DB::table('audit_logs')->insertGetId(['aksi' => 'sentinel', 'deskripsi' => 'preserve', 'created_at' => now()]);
        app()->detectEnvironment(fn (): string => 'production');

        $this->artisan('data:clean-transactions', ['--force' => true])->assertExitCode(Command::FAILURE);
        $this->assertDatabaseHas('audit_logs', ['id' => $audit]);
    }

    private function appendThreeEvents(): void
    {
        $writer = app(AuditChainWriter::class);
        foreach ([1, 2, 3] as $number) {
            $writer->append('test_event', ['number' => $number], idempotencyKey: "event-{$number}");
        }
        $this->assertTrue(app(AuditChainVerifier::class)->verify()['valid']);
    }
}
