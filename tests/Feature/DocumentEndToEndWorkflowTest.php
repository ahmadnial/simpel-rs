<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\SignatureOtpChallenge;
use App\Models\SignatureEvidence;
use App\Models\SigningCeremony;
use App\Models\DocumentType;
use App\Models\DocumentVerification;
use App\Models\DocumentVersion;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Support\RequestsSigningOtp;

class DocumentEndToEndWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use RequestsSigningOtp;

    public function test_document_can_complete_pedoman_workflow_from_submission_through_internal_signature(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['dokumen.buat', 'dokumen.verifikasi', 'dokumen.tanda_tangan'] as $permission) {
            Permission::create(['name' => $permission, 'guard_name' => 'web']);
        }
        Role::create(['name' => 'pengusul', 'guard_name' => 'web'])->givePermissionTo('dokumen.buat');
        Role::create(['name' => 'asesor_internal', 'guard_name' => 'web'])->givePermissionTo('dokumen.verifikasi');
        Role::create(['name' => 'verifikator', 'guard_name' => 'web'])->givePermissionTo('dokumen.verifikasi');
        Role::create(['name' => 'penandatangan', 'guard_name' => 'web'])->givePermissionTo('dokumen.tanda_tangan');

        $unit = Unit::create(['nama' => 'Instalasi Gawat Darurat', 'kode' => 'IGD', 'urutan' => 1]);
        $pengusul = $this->user('Pengusul IGD', 'pengusul-igd@test.com', $unit, 'pengusul');
        $asesors = collect(range(1, 4))->map(fn ($number) => $this->user("Asesor {$number}", "asesor-{$number}@test.com", $unit, 'asesor_internal'));
        $sekretariat = $this->user('Sekretariat', 'sekretariat@test.com', $unit, 'verifikator');
        $signer = $this->user('Direktur', 'direktur@test.com', $unit, 'penandatangan');

        $type = DocumentType::create([
            'nama' => 'Panduan', 'kode' => 'PAD', 'singkatan' => 'PAD',
            'format_nomor' => '{urut}/PAD/{tahun}', 'is_active' => true, 'urutan' => 1,
        ]);
        $workflow = WorkflowTemplate::create([
            'nama' => 'Standar Pedoman - Panduan', 'document_type_id' => $type->id,
            'is_active' => true, 'is_default' => true,
        ]);
        $asesorStep = WorkflowStep::create([
            'workflow_template_id' => $workflow->id, 'urutan' => 1,
            'nama_tahap' => 'Verifikasi Asesor Internal', 'tipe' => 'verifikasi',
            'mode_verifikasi' => 'parallel', 'min_approval' => 1, 'sla_hari_kerja' => 2,
        ]);
        $asesorStep->verifierPool()->create(['tipe_pool' => 'role', 'role_nama' => 'asesor_internal']);
        WorkflowStep::create([
            'workflow_template_id' => $workflow->id, 'urutan' => 2,
            'nama_tahap' => 'Verifikasi Sekretariat', 'tipe' => 'verifikasi',
            'mode_verifikasi' => 'serial', 'role_nama' => 'verifikator', 'min_approval' => 1, 'sla_hari_kerja' => 2,
        ]);
        WorkflowStep::create([
            'workflow_template_id' => $workflow->id, 'urutan' => 3,
            'nama_tahap' => 'Penandatangan', 'tipe' => 'penandatangan',
            'mode_verifikasi' => 'serial', 'role_nama' => 'penandatangan', 'min_approval' => 1, 'sla_hari_kerja' => 2,
        ]);

        $document = Document::create([
            'judul' => 'Panduan IGD E2E', 'document_type_id' => $type->id,
            'unit_id' => $unit->id, 'pengusul_id' => $pengusul->id,
            'status' => Document::STATUS_DRAFT, 'visibility_scope' => 'terbatas',
        ]);
        DocumentVersion::create([
            'document_id' => $document->id, 'versi' => 1,
            'file_path' => "documents/{$document->id}/panduan-igd.docx", 'file_name' => 'panduan-igd.docx',
            'uploaded_by' => $pengusul->id, 'is_current' => true,
        ]);

        $service = app(DocumentService::class);

        $this->actingAs($pengusul);
        $service->ajukanDokumen($document, []);
        $document->refresh();
        $this->assertSame(Document::STATUS_DIAJUKAN, $document->status);
        $this->assertSame(1, $document->current_step);
        $this->assertCount(4, $document->verifications()->where('status', DocumentVerification::STATUS_MENUNGGU)->get());

        $firstTicket = $document->verifications()->where('verifikator_id', $asesors->first()->id)->firstOrFail();
        $this->actingAs($asesors->first());
        $service->setujui($firstTicket, 'Sesuai pedoman.');

        $document->refresh();
        $this->assertSame(Document::STATUS_VERIFIKASI, $document->status);
        $this->assertSame(2, $document->current_step);
        $this->assertSame(3, $document->verifications()->where('level', 1)->where('status', DocumentVerification::STATUS_DIBATALKAN)->count());

        $secretaryTicket = $document->verifications()->where('verifikator_id', $sekretariat->id)->where('status', DocumentVerification::STATUS_MENUNGGU)->firstOrFail();
        $this->actingAs($sekretariat);
        $service->setujui($secretaryTicket, 'Format dan administrasi sesuai.');

        $document->refresh();
        $this->assertSame(Document::STATUS_MENUNGGU_TTD, $document->status);
        $this->assertSame(3, $document->current_step);

        $this->actingAs($signer);
        $otpRequest = $this->requestSigningOtp($signer, $document);
        $signed = $service->tandaTangani($document, $otpRequest['otp'], $otpRequest['session_id']);

        $this->assertSame(Document::STATUS_DITANDATANGANI, $signed->status);
        $this->assertNotEmpty($signed->nomor_surat);
        $signature = DocumentSignature::where('document_id', $document->id)->firstOrFail();
        $this->assertSame($signer->id, $signature->penandatangan_id);
        $this->assertTrue(Storage::disk('local')->exists($signature->file_signed_path));
        $this->assertSame($signature->hash_dokumen, hash_file('sha256', Storage::disk('local')->path($signature->file_signed_path)));
        $receipt = SignatureOtpChallenge::where('document_id', $document->id)->firstOrFail();
        $this->assertNotNull($receipt->requested_at);
        $this->assertNotNull($receipt->sent_at);
        $this->assertNotNull($receipt->verified_at);
        $this->assertNotNull($receipt->consumed_at);
        $this->assertNotNull($receipt->sealed_at);
        $ceremony = SigningCeremony::where('document_id', $document->id)->firstOrFail();
        $evidence = SignatureEvidence::where('document_id', $document->id)->firstOrFail();
        $this->assertSame(SigningCeremony::STATE_SEALED, $ceremony->state);
        $this->assertSame('immutable_verified', $evidence->state);
        $this->assertCount(6, $evidence->storageCopies);
        $this->assertSame($signature->hash_dokumen, $evidence->pdf_hash);
        $this->assertSame($ceremony->candidate_pdf_hash, $evidence->pdf_hash);
        $this->assertSame($evidence->manifest_hash, hash('sha256', $evidence->canonical_manifest));
        $this->assertSame($evidence->uuid, json_decode($evidence->canonical_manifest, true, flags: JSON_THROW_ON_ERROR)['evidence_id']);
        $this->assertStringNotContainsString($receipt->otp_verifier, $evidence->canonical_manifest);
        $this->assertNotSame(str_repeat('0', 64), $signature->hash_dokumen);
        try {
            $evidence->update(['canonical_manifest' => '{}']);
            $this->fail('Canonical manifest evidence tidak boleh diubah.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
    }

    private function user(string $name, string $email, Unit $unit, string $role): User
    {
        $user = User::create([
            'name' => $name, 'email' => $email, 'password' => bcrypt('password'),
            'unit_id' => $unit->id, 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
