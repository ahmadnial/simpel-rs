<?php

namespace Tests\Feature;

use App\Contracts\EvidenceSigner;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\SignatureEvidence;
use App\Models\SigningKey;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Services\CanonicalJson;
use App\Services\DatabaseSigningKeyRegistry;
use App\Services\DocumentService;
use App\Services\EvidenceBundleService;
use App\Services\EvidenceStatusService;
use App\Services\EvidenceVerificationService;
use App\Support\EvidenceSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\RequestsSigningOtp;
use Tests\TestCase;
use ZipArchive;

class EvidenceBundleVerificationTest extends TestCase
{
    use RefreshDatabase;
    use RequestsSigningOtp;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'penandatangan', 'guard_name' => 'web']);
    }

    public function test_signed_bundle_verifies_online_and_offline_with_pinned_public_key(): void
    {
        $evidence = $this->signDocument();
        $key = app(EvidenceSigner::class)->activeKey();
        $trustedPath = Storage::disk('local')->path('trusted-key.json');
        Storage::disk('local')->put('trusted-key.json', app(CanonicalJson::class)->encode($key->toArray()));

        $online = app(EvidenceVerificationService::class)->verifyEvidence($evidence);
        $offline = app(EvidenceVerificationService::class)->verifyBundle(
            Storage::disk('local')->path($evidence->bundle_path),
            $key->toArray(),
        );

        $this->assertTrue($online['valid']);
        $this->assertTrue($offline['valid']);
        $this->assertSame('immutable_verified', $evidence->state);
        $this->assertCount(6, $evidence->storageCopies);
        $this->assertTrue($online['checks']['audit_chain']);
        $this->assertTrue($online['checks']['audit_checkpoint']);
        $this->assertTrue($online['checks']['immutable_storage']);
        $this->artisan('tte:audit-verify', ['--json' => true])->assertExitCode(0);
        $this->artisan('evidence:reconcile', ['uuid' => $evidence->uuid, '--json' => true])->assertExitCode(0);
        $this->artisan('evidence:verify-bundle', [
            'path' => Storage::disk('local')->path($evidence->bundle_path),
            '--public-key' => $trustedPath,
            '--json' => true,
        ])->assertExitCode(0);
        $this->get(route('public.keys.active'))
            ->assertOk()
            ->assertJsonPath('key_id', $key->keyId)
            ->assertJsonPath('fingerprint', $key->fingerprint);

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($evidence->bundle_path));
        $this->assertStringNotContainsString($evidence->otpChallenge->otp_verifier, $zip->getFromName('otp-receipt.json'));
        $this->assertStringNotContainsString('otp_verifier', $zip->getFromName('evidence-manifest.json'));
        $zip->close();
    }

    public function test_one_byte_change_in_each_critical_bundle_artifact_is_detected(): void
    {
        $evidence = $this->signDocument();
        $key = app(EvidenceSigner::class)->activeKey()->toArray();
        $original = Storage::disk('local')->path($evidence->bundle_path);

        foreach (['document.pdf', 'evidence-manifest.json', 'otp-receipt.json', 'institution-signature.json'] as $entry) {
            $copy = Storage::disk('local')->path('tampered-'.str_replace('.', '-', $entry).'.zip');
            copy($original, $copy);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($copy) === true);
            $content = $zip->getFromName($entry);
            $zip->addFromString($entry, $content.'!');
            $zip->close();

            $result = app(EvidenceVerificationService::class)->verifyBundle($copy, $key);
            $this->assertFalse($result['valid'], "Tamper {$entry} harus terdeteksi.");
        }
    }

    public function test_bundle_generation_is_deterministic_and_key_rotation_semantics_are_preserved(): void
    {
        $evidence = $this->signDocument();
        $key = app(EvidenceSigner::class)->activeKey();
        $signature = new EvidenceSignature($key, $evidence->institution_signature);
        $firstHash = $evidence->bundle_hash;
        $rebuilt = app(EvidenceBundleService::class)->build(
            $evidence->uuid,
            Storage::disk('local')->path($evidence->pdf_path),
            $evidence->canonical_manifest,
            $evidence->otp_receipt,
            $signature,
        );
        $this->assertSame($firstHash, $rebuilt['hash']);

        SigningKey::create([
            'key_id' => $key->keyId,
            'algorithm' => $key->algorithm,
            'purpose' => 'evidence_manifest',
            'public_key' => $key->publicKey,
            'fingerprint' => $key->fingerprint,
            'status' => 'retired',
            'activated_at' => now()->subDay(),
            'retired_at' => now(),
            'policy_version' => 'key-policy-v1',
        ]);
        $registry = new DatabaseSigningKeyRegistry;
        $verifier = new EvidenceVerificationService(app(CanonicalJson::class), $registry);
        $this->assertTrue($verifier->verifyEvidence($evidence)['valid'], 'Retired key tetap memverifikasi bukti lama.');

        SigningKey::where('key_id', $key->keyId)->update(['status' => 'revoked', 'revoked_at' => now(), 'reason' => 'test compromise']);
        $revoked = $verifier->verifyEvidence($evidence);
        $this->assertFalse($revoked['valid']);
        $this->assertSame('revoked', $revoked['key_status']);
    }

    public function test_database_manifest_and_hash_changed_together_cannot_forge_institution_signature(): void
    {
        $evidence = $this->signDocument();
        $changedManifest = substr($evidence->canonical_manifest, 0, -1).',"tampered":true}';
        DB::table('signature_evidence')->where('id', $evidence->id)->update([
            'canonical_manifest' => $changedManifest,
            'manifest_hash' => hash('sha256', $changedManifest),
        ]);

        $result = app(EvidenceVerificationService::class)->verifyEvidence($evidence->fresh());
        $this->assertFalse($result['valid']);
        $this->assertFalse($result['checks']['institution_signature']);
    }

    public function test_administrative_revocation_is_append_only_and_does_not_rewrite_historical_evidence(): void
    {
        $evidence = $this->signDocument();
        $originalManifest = $evidence->canonical_manifest;
        $event = app(EvidenceStatusService::class)->record(
            $evidence,
            'revoked',
            'Simulasi insiden keamanan',
            'INC-TEST-001',
        );

        $result = app(EvidenceVerificationService::class)->verifyEvidence($evidence->fresh());
        $this->assertTrue($result['valid'], 'Fakta integritas historis tetap valid setelah revocation administratif.');
        $this->assertSame('revoked', $result['administrative_status']);
        $this->assertSame($originalManifest, $evidence->fresh()->canonical_manifest);
        $this->assertNotNull($event->audit_chain_event_id);
        $this->assertNotNull($event->audit_checkpoint_id);
        $this->expectException(\LogicException::class);
        $event->update(['reason' => 'ditulis ulang']);
    }

    public function test_reconcile_command_succeeds_with_valid_empty_json_when_no_evidence_exists(): void
    {
        $this->artisan('evidence:reconcile', ['--json' => true])
            ->assertExitCode(0)
            ->expectsOutput(json_encode(['valid' => true, 'evidence' => []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function test_reconcile_command_fails_when_specific_uuid_is_not_found(): void
    {
        $this->artisan('evidence:reconcile', ['uuid' => (string) \Illuminate\Support\Str::uuid(), '--json' => true])
            ->assertExitCode(2);
    }

    private function signDocument(): SignatureEvidence
    {
        $suffix = bin2hex(random_bytes(4));
        $unit = Unit::create(['nama' => "Unit {$suffix}", 'kode' => "U{$suffix}", 'urutan' => 1]);
        $proposer = $this->user('Proposer', "proposer-{$suffix}@example.test", $unit);
        $signer = $this->user('Signer', "signer-{$suffix}@example.test", $unit);
        $signer->assignRole('penandatangan');
        $type = DocumentType::create([
            'nama' => "Type {$suffix}", 'kode' => "T{$suffix}", 'singkatan' => 'TTE',
            'format_nomor' => "{urut}/TTE/{$suffix}/{tahun}", 'is_active' => true, 'urutan' => 1,
        ]);
        $workflow = WorkflowTemplate::create([
            'nama' => "Workflow {$suffix}", 'document_type_id' => $type->id, 'is_default' => true, 'is_active' => true,
        ]);
        WorkflowStep::create([
            'workflow_template_id' => $workflow->id, 'urutan' => 1, 'nama_tahap' => 'Signer',
            'tipe' => 'penandatangan', 'role_nama' => 'penandatangan', 'mode_verifikasi' => 'serial', 'sla_hari_kerja' => 2,
        ]);
        $document = Document::create([
            'judul' => "Document {$suffix}", 'document_type_id' => $type->id, 'unit_id' => $unit->id,
            'pengusul_id' => $proposer->id, 'workflow_template_id' => $workflow->id,
            'status' => Document::STATUS_MENUNGGU_TTD, 'current_step' => 1,
        ]);
        DocumentVersion::create([
            'document_id' => $document->id, 'versi' => 1, 'file_path' => "documents/{$document->id}/bundle.docx",
            'file_name' => 'bundle.docx', 'uploaded_by' => $proposer->id, 'is_current' => true,
        ]);
        $document = $document->fresh(['currentVersion', 'workflowTemplate']);
        $this->actingAs($signer);
        $otp = $this->requestSigningOtp($signer, $document, 'bundle-session');
        app(DocumentService::class)->tandaTangani($document, $otp['otp'], $otp['session_id']);

        return SignatureEvidence::with('otpChallenge')->where('document_id', $document->id)->firstOrFail();
    }

    private function user(string $name, string $email, Unit $unit): User
    {
        return User::create([
            'name' => $name, 'email' => $email, 'password' => bcrypt('password'),
            'unit_id' => $unit->id, 'is_active' => true,
        ]);
    }
}
