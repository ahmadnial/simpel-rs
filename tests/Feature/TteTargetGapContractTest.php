<?php

namespace Tests\Feature;

use App\Contracts\OtpSecretProvider;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\SignatureOtpChallenge;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Notifications\OtpTandaTangan;
use App\Services\SigningOtpService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Kontrak gap Paket A kini menjadi regression test wajib setelah seluruh paket selesai. */
class TteTargetGapContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'penandatangan', 'guard_name' => 'web']);
    }

    public function test_debug_otp_is_never_exposed_outside_automated_testing_even_when_debug_is_enabled(): void
    {
        Notification::fake();
        config(['app.debug' => true]);
        app(OtpSecretProvider::class);
        app()->detectEnvironment(fn (): string => 'staging');
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $fixture = $this->signingFixture();

        $this->actingAs($fixture['signer'])
            ->withSession(['auth_password_confirmed_at' => now()->timestamp])
            ->postJson(route('ttd.kirim-otp', $fixture['document']))
            ->assertOk()
            ->assertJsonMissingPath('debug_otp');
    }

    public function test_otp_policy_uses_eight_digits_and_three_minute_expiry(): void
    {
        Notification::fake();
        $fixture = $this->signingFixture();

        $this->actingAs($fixture['signer'])
            ->withSession(['auth_password_confirmed_at' => now()->timestamp])
            ->postJson(route('ttd.kirim-otp', $fixture['document']))
            ->assertOk();

        $notificationSent = null;
        Notification::assertSentTo(
            $fixture['signer'],
            OtpTandaTangan::class,
            function (OtpTandaTangan $notification) use (&$notificationSent): bool {
                $notificationSent = $notification;

                return true;
            }
        );

        $this->assertMatchesRegularExpression('/^\d{8}$/', $notificationSent->otp);
        $this->assertSame(3, $notificationSent->expiryMinutes);
    }

    public function test_otp_is_revoked_when_session_lineage_changes(): void
    {
        Notification::fake();
        $fixture = $this->signingFixture();
        $response = $this->actingAs($fixture['signer'])
            ->withSession(['auth_password_confirmed_at' => now()->timestamp])
            ->postJson(route('ttd.kirim-otp', $fixture['document']))
            ->assertOk();

        $otp = null;
        Notification::assertSentTo(
            $fixture['signer'],
            OtpTandaTangan::class,
            function (OtpTandaTangan $notification) use (&$otp): bool {
                $otp = $notification->otp;

                return true;
            }
        );

        $challenge = SignatureOtpChallenge::latest('id')->firstOrFail();
        $this->app['session']->invalidate();

        try {
            app(SigningOtpService::class)->verifyAndConsume($fixture['signer'], $fixture['document'], $otp, [
                'document_version_id' => $challenge->document_version_id,
                'pdf_hash' => $challenge->pdf_hash,
                'manifest_draft_hash' => $challenge->manifest_draft_hash,
                'session_id' => $this->app['session']->getId(),
                'action' => $challenge->action,
                'reauthentication_age_seconds' => 0,
                'signing_ceremony_id' => $challenge->signing_ceremony_id,
            ]);
            $this->fail('OTP dari session lineage lama harus ditolak.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_clean_transactions_command_fails_closed_in_production_and_preserves_audit(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('directories')->andReturn([]);
        $audit = AuditLog::create([
            'aksi' => 'baseline_guard_sentinel',
            'deskripsi' => 'Tidak boleh terhapus di production.',
        ]);

        $this->artisan('data:clean-transactions', ['--force' => true])
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseHas('audit_logs', ['id' => $audit->id]);
    }

    /**
     * @return array{signer: User, document: Document}
     */
    private function signingFixture(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $unit = Unit::create(['nama' => "Unit {$suffix}", 'kode' => "U{$suffix}", 'urutan' => 1]);
        $proposer = $this->user('Proposer', "proposer-{$suffix}@example.test", $unit);
        $signer = $this->user('Signer', "signer-{$suffix}@example.test", $unit);
        $signer->assignRole('penandatangan');
        $type = DocumentType::create([
            'nama' => "Type {$suffix}",
            'kode' => "T{$suffix}",
            'singkatan' => 'TTE',
            'format_nomor' => "{urut}/TTE/{$suffix}/{tahun}",
            'is_active' => true,
            'urutan' => 1,
        ]);
        $workflow = WorkflowTemplate::create([
            'nama' => "Workflow {$suffix}",
            'document_type_id' => $type->id,
            'is_default' => true,
            'is_active' => true,
        ]);
        WorkflowStep::create([
            'workflow_template_id' => $workflow->id,
            'urutan' => 1,
            'nama_tahap' => 'Penandatangan',
            'tipe' => 'penandatangan',
            'role_nama' => 'penandatangan',
            'mode_verifikasi' => 'serial',
            'sla_hari_kerja' => 2,
        ]);
        $document = Document::create([
            'judul' => "Dokumen {$suffix}",
            'document_type_id' => $type->id,
            'unit_id' => $unit->id,
            'pengusul_id' => $proposer->id,
            'workflow_template_id' => $workflow->id,
            'status' => Document::STATUS_MENUNGGU_TTD,
            'current_step' => 1,
        ]);
        DocumentVersion::create([
            'document_id' => $document->id,
            'versi' => 1,
            'file_path' => "documents/{$document->id}/baseline.docx",
            'file_name' => 'baseline.docx',
            'uploaded_by' => $proposer->id,
            'is_current' => true,
        ]);

        return compact('signer') + ['document' => $document->fresh('currentVersion')];
    }

    private function user(string $name, string $email, Unit $unit): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'unit_id' => $unit->id,
            'is_active' => true,
        ]);
    }
}
