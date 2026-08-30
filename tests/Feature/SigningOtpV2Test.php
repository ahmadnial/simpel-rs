<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\SignatureOtpChallenge;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Notifications\OtpTandaTangan;
use App\Services\DocumentService;
use App\Services\SigningOtpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SigningOtpV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tte.otp.delivery' => 'email']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'penandatangan', 'guard_name' => 'web']);
        Notification::fake();
    }

    public function test_challenge_stores_transaction_binding_and_hmac_verifier_without_plaintext_otp(): void
    {
        $fixture = $this->fixture();
        $context = $this->context($fixture, 'session-policy');
        $challenge = app(SigningOtpService::class)->request($fixture['signer'], $fixture['document'], $context);
        $otp = $this->otpFor($fixture['signer'], $challenge);

        $challenge->refresh();
        $this->assertMatchesRegularExpression('/^\d{8}$/', $otp);
        $this->assertSame(SignatureOtpChallenge::STATE_SENT, $challenge->state);
        $this->assertSame($fixture['signer']->id, $challenge->user_id);
        $this->assertSame($fixture['document']->id, $challenge->document_id);
        $this->assertSame($fixture['document']->currentVersion->id, $challenge->document_version_id);
        $this->assertSame($context['pdf_hash'], $challenge->pdf_hash);
        $this->assertSame($context['manifest_draft_hash'], $challenge->manifest_draft_hash);
        $this->assertSame(hash('sha256', 'session-policy'), $challenge->session_id_hash);
        $this->assertSame(180.0, $challenge->requested_at->diffInSeconds($challenge->expires_at));
        $this->assertNotSame($otp, $challenge->otp_verifier);
        $this->assertStringNotContainsString($otp, json_encode($challenge->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertNull($fixture['signer']->fresh()->otp_hash);
        $notification = Notification::sent($fixture['signer'], OtpTandaTangan::class)->first();
        $this->assertStringNotContainsString($otp, $notification->toMail($fixture['signer'])->subject);
        $this->assertStringNotContainsString($otp, json_encode($notification->toArray($fixture['signer']), JSON_THROW_ON_ERROR));
        $this->assertNotInstanceOf(ShouldQueue::class, $notification);
    }

    public function test_resend_revokes_prior_generation_and_only_latest_challenge_can_be_consumed_once(): void
    {
        $fixture = $this->fixture();
        $context = $this->context($fixture, 'session-resend');
        $first = app(SigningOtpService::class)->request($fixture['signer'], $fixture['document'], $context);
        $firstOtp = $this->otpFor($fixture['signer'], $first);
        $second = app(SigningOtpService::class)->request($fixture['signer'], $fixture['document'], $context);
        $secondOtp = $this->otpFor($fixture['signer'], $second);

        $this->assertSame(SignatureOtpChallenge::STATE_REVOKED, $first->fresh()->state);
        $this->assertSame(2, $second->resend_generation);
        $this->expectOtpFailure(fn () => app(SigningOtpService::class)->verifyAndConsume($fixture['signer'], $fixture['document'], $firstOtp, $context));

        $consumed = app(SigningOtpService::class)->verifyAndConsume($fixture['signer'], $fixture['document'], $secondOtp, $context);
        $this->assertSame(SignatureOtpChallenge::STATE_CONSUMED, $consumed->state);
        $this->expectOtpFailure(fn () => app(SigningOtpService::class)->verifyAndConsume($fixture['signer'], $fixture['document'], $secondOtp, $context));
        $this->assertSame(1, SignatureOtpChallenge::whereNotNull('consumed_at')->count());
    }

    public function test_session_pdf_manifest_and_document_binding_mismatch_revokes_challenge(): void
    {
        foreach (['session_id', 'pdf_hash', 'manifest_draft_hash'] as $field) {
            $fixture = $this->fixture();
            $context = $this->context($fixture, "session-binding-{$field}");
            $challenge = app(SigningOtpService::class)->request($fixture['signer'], $fixture['document'], $context);
            $otp = $this->otpFor($fixture['signer'], $challenge);
            $changed = $context;
            $changed[$field] = $field === 'session_id' ? 'different-session' : str_repeat('f', 64);

            $this->expectOtpFailure(fn () => app(SigningOtpService::class)->verifyAndConsume($fixture['signer'], $fixture['document'], $otp, $changed));
            $this->assertSame(SignatureOtpChallenge::STATE_REVOKED, $challenge->fresh()->state);
        }

        $fixtureA = $this->fixture();
        $fixtureB = $this->fixture($fixtureA['signer']);
        $contextA = $this->context($fixtureA, 'document-a-session');
        $challengeA = app(SigningOtpService::class)->request($fixtureA['signer'], $fixtureA['document'], $contextA);
        $otpA = $this->otpFor($fixtureA['signer'], $challengeA);
        $contextB = $this->context($fixtureB, 'document-a-session');
        $this->expectOtpFailure(fn () => app(SigningOtpService::class)->verifyAndConsume($fixtureA['signer'], $fixtureB['document'], $otpA, $contextB));
    }

    public function test_five_wrong_attempts_lock_challenge_and_attempts_persist(): void
    {
        $fixture = $this->fixture();
        $context = $this->context($fixture, 'session-attempts');
        $challenge = app(SigningOtpService::class)->request($fixture['signer'], $fixture['document'], $context);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->expectOtpFailure(fn () => app(SigningOtpService::class)->verifyAndConsume($fixture['signer'], $fixture['document'], '99999999', $context));
            $this->assertSame($attempt, $challenge->fresh()->attempt_count);
        }

        $this->assertSame(SignatureOtpChallenge::STATE_LOCKED, $challenge->fresh()->state);
        $this->assertNull($challenge->fresh()->active_binding_key);
    }

    public function test_expiry_and_persistent_send_rate_limit_fail_closed(): void
    {
        Carbon::setTestNow('2026-08-29 10:00:00 UTC');
        $fixture = $this->fixture();
        $context = $this->context($fixture, 'session-expiry');
        $challenge = app(SigningOtpService::class)->request($fixture['signer'], $fixture['document'], $context);
        $otp = $this->otpFor($fixture['signer'], $challenge);
        Carbon::setTestNow(now()->addSeconds(180));

        $this->expectOtpFailure(fn () => app(SigningOtpService::class)->verifyAndConsume($fixture['signer'], $fixture['document'], $otp, $context));
        $this->assertSame(SignatureOtpChallenge::STATE_EXPIRED, $challenge->fresh()->state);

        Carbon::setTestNow('2026-08-29 11:00:00 UTC');
        $rateFixture = $this->fixture();
        $rateContext = $this->context($rateFixture, 'session-rate');
        for ($send = 0; $send < 3; $send++) {
            app(SigningOtpService::class)->request($rateFixture['signer'], $rateFixture['document'], $rateContext);
        }
        try {
            app(SigningOtpService::class)->request($rateFixture['signer'], $rateFixture['document'], $rateContext);
            $this->fail('Pengiriman keempat dalam 15 menit harus ditolak persisten.');
        } catch (HttpException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_email_change_and_explicit_security_revocation_invalidate_active_challenge(): void
    {
        $fixture = $this->fixture();
        $context = $this->context($fixture, 'session-security-change');
        $challenge = app(SigningOtpService::class)->request($fixture['signer'], $fixture['document'], $context);
        $otp = $this->otpFor($fixture['signer'], $challenge);
        $fixture['signer']->update(['email' => 'changed@example.test']);

        $this->expectOtpFailure(fn () => app(SigningOtpService::class)->verifyAndConsume($fixture['signer']->fresh(), $fixture['document'], $otp, $context));
        $this->assertSame(SignatureOtpChallenge::STATE_REVOKED, $challenge->fresh()->state);

        $newContext = $this->context($fixture, 'session-security-change-2');
        $newChallenge = app(SigningOtpService::class)->request($fixture['signer']->fresh(), $fixture['document'], $newContext);
        $fixture['signer']->update(['password' => bcrypt('different-password')]);
        $this->assertSame('account_security_changed', $newChallenge->fresh()->failure_reason);

        $roleContext = $this->context($fixture, 'session-security-change-3');
        $roleChallenge = app(SigningOtpService::class)->request($fixture['signer']->fresh(), $fixture['document'], $roleContext);
        $fixture['signer']->syncRoles([]);
        $this->assertSame('role_changed', $roleChallenge->fresh()->failure_reason);
    }

    public function test_otp_email_field_routes_notification_to_dedicated_address_instead_of_login_email(): void
    {
        $fixture = $this->fixture();
        $fixture['signer']->update(['otp_email' => 'director-inbox@example.test']);
        $context = $this->context($fixture, 'session-otp-email');
        $challenge = app(SigningOtpService::class)->request($fixture['signer']->fresh(), $fixture['document'], $context);
        $otp = $this->otpFor($fixture['signer'], $challenge);

        $notification = Notification::sent($fixture['signer'], OtpTandaTangan::class)->first();
        $this->assertSame('director-inbox@example.test', $fixture['signer']->fresh()->routeNotificationForMail($notification));
        $this->assertNotSame($fixture['signer']->email, $fixture['signer']->fresh()->routeNotificationForMail($notification));
        $this->assertMatchesRegularExpression('/^\d{8}$/', $otp);
    }

    public function test_otp_email_falls_back_to_login_email_when_not_set(): void
    {
        $fixture = $this->fixture();
        $this->assertNull($fixture['signer']->otp_email);
        $notification = new OtpTandaTangan(otp: '12345678', expiryMinutes: 3, challengeId: 'abcd1234', documentTitle: 'X', documentNumber: 'Y');
        $this->assertSame($fixture['signer']->email, $fixture['signer']->routeNotificationForMail($notification));
    }

    public function test_otp_email_change_invalidates_active_challenge_same_as_login_email_change(): void
    {
        $fixture = $this->fixture();
        $fixture['signer']->update(['otp_email' => 'first-inbox@example.test']);
        $context = $this->context($fixture, 'session-otp-email-change');
        $challenge = app(SigningOtpService::class)->request($fixture['signer']->fresh(), $fixture['document'], $context);
        $otp = $this->otpFor($fixture['signer'], $challenge);

        $fixture['signer']->update(['otp_email' => 'second-inbox@example.test']);

        $this->expectOtpFailure(fn () => app(SigningOtpService::class)->verifyAndConsume($fixture['signer']->fresh(), $fixture['document'], $otp, $context));
        $this->assertSame(SignatureOtpChallenge::STATE_REVOKED, $challenge->fresh()->state);
    }

    public function test_http_request_requires_recent_password_reauthentication(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['signer'])
            ->postJson(route('ttd.kirim-otp', $fixture['document']))
            ->assertStatus(423);

        $this->actingAs($fixture['signer'])
            ->withSession(['auth_password_confirmed_at' => now()->subSeconds(301)->timestamp])
            ->postJson(route('ttd.kirim-otp', $fixture['document']))
            ->assertStatus(423);
    }

    public function test_otp_endpoint_returns_json_for_a_browser_fetch_request_that_expects_json(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['signer'])
            ->post(route('ttd.kirim-otp', $fixture['document']), [], ['Accept' => 'application/json'])
            ->assertStatus(423)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', 'Konfirmasi ulang password diperlukan sebelum meminta OTP.');
    }

    public function test_local_display_delivery_returns_otp_without_sending_email(): void
    {
        config(['tte.otp.delivery' => 'display']);
        $fixture = $this->fixture();

        $this->actingAs($fixture['signer'])
            ->withSession(['auth_password_confirmed_at' => now()->timestamp])
            ->postJson(route('ttd.kirim-otp', $fixture['document']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'OTP ditampilkan hanya untuk lingkungan lokal (berlaku 3 menit).')
            ->assertJsonStructure(['otp'])
            ->assertJsonPath('otp', fn (string $otp): bool => (bool) preg_match('/^\\d{8}$/', $otp));

        Notification::assertNothingSent();
    }

    public function test_database_rejects_two_active_challenges_for_the_same_binding(): void
    {
        $fixture = $this->fixture();
        $context = $this->context($fixture, 'session-unique-active');
        $challenge = app(SigningOtpService::class)->request($fixture['signer'], $fixture['document'], $context);
        $duplicate = $challenge->replicate();
        $duplicate->uuid = (string) Str::uuid();
        $duplicate->correlation_id = (string) Str::uuid();

        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    /**
     * @return array{unit:Unit,signer:User,document:Document}
     */
    private function fixture(?User $signer = null): array
    {
        $suffix = bin2hex(random_bytes(4));
        $unit = Unit::create(['nama' => "Unit {$suffix}", 'kode' => "U{$suffix}", 'urutan' => 1]);
        $proposer = $this->user('Proposer', "proposer-{$suffix}@example.test", $unit);
        $signer ??= $this->user('Signer', "signer-{$suffix}@example.test", $unit);
        if (! $signer->hasRole('penandatangan')) {
            $signer->assignRole('penandatangan');
        }
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
            'document_id' => $document->id, 'versi' => 1, 'file_path' => "documents/{$document->id}/otp-v2.docx",
            'file_name' => 'otp-v2.docx', 'uploaded_by' => $proposer->id, 'is_current' => true,
        ]);

        return compact('unit', 'signer') + ['document' => $document->fresh(['currentVersion', 'workflowTemplate'])];
    }

    /** @param array{signer:User,document:Document} $fixture */
    private function context(array $fixture, string $sessionId): array
    {
        return app(DocumentService::class)->prepareOtpContext($fixture['document'], $fixture['signer'], $sessionId);
    }

    private function otpFor(User $user, SignatureOtpChallenge $challenge): string
    {
        $otp = null;
        Notification::assertSentTo($user, OtpTandaTangan::class, function (OtpTandaTangan $notification) use ($challenge, &$otp): bool {
            if ($notification->challengeId !== substr($challenge->uuid, 0, 8)) {
                return false;
            }
            $otp = $notification->otp;

            return true;
        });

        return $otp;
    }

    private function expectOtpFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Operasi OTP seharusnya ditolak.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    private function user(string $name, string $email, Unit $unit): User
    {
        return User::create([
            'name' => $name, 'email' => $email, 'password' => bcrypt('password'),
            'unit_id' => $unit->id, 'is_active' => true,
        ]);
    }
}
