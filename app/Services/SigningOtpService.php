<?php

namespace App\Services;

use App\Contracts\OtpSecretProvider;
use App\Contracts\OtpVerifier;
use App\Models\Document;
use App\Models\SignatureOtpChallenge;
use App\Models\SigningCeremony;
use App\Models\User;
use App\Notifications\OtpTandaTangan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class SigningOtpService
{
    public function __construct(
        private readonly OtpVerifier $verifier,
        private readonly OtpSecretProvider $secrets,
        private readonly SecurityEventReporter $securityEvents,
    ) {}

    /**
     * @param  array{document_version_id:int, pdf_hash:string, manifest_draft_hash:string, session_id:string, action?:string, correlation_id?:string, source_ip?:string|null, user_agent?:string|null, reauthentication_age_seconds?:int|null}  $context
     */
    public function request(User $user, Document $document, array $context): SignatureOtpChallenge
    {
        $this->assertAuthorizedSigner($document, $user);
        $this->assertContext($document, $context);
        $ceremony = SigningCeremony::where('uuid', $context['signing_ceremony_id'])->first();
        abort_unless(
            $ceremony
            && $ceremony->state === SigningCeremony::STATE_AWAITING_USER_SIGNATURE
            && $ceremony->document_id === (int) $document->id
            && $ceremony->document_version_id === (int) $context['document_version_id']
            && $ceremony->intended_actor_id === (int) $user->id
            && hash_equals($ceremony->candidate_pdf_hash, strtolower($context['pdf_hash']))
            && hash_equals($ceremony->manifest_draft_hash, strtolower($context['manifest_draft_hash'])),
            409,
            'Ceremony signing tidak cocok dengan challenge OTP.'
        );
        $action = $context['action'] ?? (string) config('tte.otp.action');
        $now = now();
        $otp = $this->generateOtp();
        $challengeUuid = (string) Str::uuid();
        $destination = $this->normalizeDestination($user->otpDeliveryEmail());
        $delivery = (string) config('tte.otp.delivery', 'email');
        abort_unless(in_array($delivery, ['email', 'display'], true), 500, 'Mode pengiriman OTP tidak valid.');
        abort_unless($delivery !== 'display' || app()->environment(['local', 'testing']), 403, 'Mode tampil OTP hanya diizinkan pada local/testing.');

        $challenge = DB::transaction(function () use ($user, $document, $context, $action, $now, $otp, $challengeUuid, $destination) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($lockedUser->is_active, 403, 'Akun penandatangan tidak aktif.');

            $this->enforcePersistentRateLimit($lockedUser, $document, $now);

            $active = SignatureOtpChallenge::query()
                ->where('user_id', $lockedUser->id)
                ->where('document_id', $document->id)
                ->where('document_version_id', $context['document_version_id'])
                ->where('action', $action)
                ->whereNotNull('active_binding_key')
                ->lockForUpdate()
                ->get();

            $generation = ((int) SignatureOtpChallenge::query()
                ->where('user_id', $lockedUser->id)
                ->where('document_id', $document->id)
                ->where('document_version_id', $context['document_version_id'])
                ->where('action', $action)
                ->max('resend_generation')) + 1;

            foreach ($active as $oldChallenge) {
                $this->transitionToTerminal($oldChallenge, SignatureOtpChallenge::STATE_REVOKED, 'resend_replaced');
            }

            $bindingKey = $this->activeBindingKey($lockedUser->id, $document->id, $context['document_version_id'], $action);

            return SignatureOtpChallenge::create([
                'uuid' => $challengeUuid,
                'user_id' => $lockedUser->id,
                'document_id' => $document->id,
                'document_version_id' => $context['document_version_id'],
                'signing_ceremony_id' => $context['signing_ceremony_id'] ?? null,
                'pdf_hash' => strtolower($context['pdf_hash']),
                'manifest_draft_hash' => strtolower($context['manifest_draft_hash']),
                'nonce_hash' => hash('sha256', random_bytes(32)),
                'session_id_hash' => $this->sessionHash($context['session_id']),
                'action' => $action,
                'policy_version' => config('tte.otp.policy_version'),
                'otp_verifier' => $this->verifier->make($challengeUuid, $otp),
                'masked_destination' => $this->maskDestination($destination),
                'destination_hash' => hash_hmac('sha256', $destination, $this->secrets->destinationKey()),
                'resend_generation' => $generation,
                'attempt_count' => 0,
                'max_attempts' => config('tte.otp.max_attempts'),
                'state' => SignatureOtpChallenge::STATE_PENDING_SEND,
                'active_binding_key' => $bindingKey,
                'correlation_id' => $context['correlation_id'] ?? (string) Str::uuid(),
                'source_ip_hash' => $this->optionalMetadataHash($context['source_ip'] ?? null),
                'user_agent_hash' => $this->optionalMetadataHash($context['user_agent'] ?? null),
                'authorization_result' => true,
                'reauthentication_age_seconds' => $context['reauthentication_age_seconds'] ?? null,
                'requested_at' => $now,
                'expires_at' => $now->copy()->addSeconds((int) config('tte.otp.ttl_seconds')),
            ]);
        }, 3);

        try {
            if ($delivery === 'email') {
                $user->notify(new OtpTandaTangan(
                    otp: $otp,
                    expiryMinutes: (int) ceil(config('tte.otp.ttl_seconds') / 60),
                    challengeId: substr($challenge->uuid, 0, 8),
                    documentTitle: $document->judul,
                    documentNumber: $document->nomor_surat,
                ));
            }
            $challenge = $this->markSent($challenge);
            if ($delivery === 'display') {
                // Atribut transient: tidak pernah disimpan ke database atau evidence.
                $challenge->setAttribute('display_otp', $otp);
            }
            if ($challenge->signing_ceremony_id) {
                SigningCeremony::where('uuid', $challenge->signing_ceremony_id)->update(['otp_challenge_id' => $challenge->id]);
            }
            $this->securityEvents->report($delivery === 'display' ? 'otp_displayed_local' : 'otp_sent', [
                'actor_id' => $user->id,
                'challenge_id' => $challenge->uuid,
                'document_id' => $document->id,
                'generation' => $challenge->resend_generation,
            ]);
        } catch (Throwable $exception) {
            $this->markSendFailed($challenge);
            $this->securityEvents->report('otp_send_failed', [
                'actor_id' => $user->id,
                'challenge_id' => $challenge->uuid,
                'document_id' => $document->id,
            ], 'error');
            throw new HttpException(503, 'OTP tidak dapat dikirim. Silakan coba kembali.', $exception);
        } finally {
            unset($otp);
        }

        return $challenge;
    }

    public function markSent(SignatureOtpChallenge $challenge): SignatureOtpChallenge
    {
        return DB::transaction(function () use ($challenge) {
            $locked = SignatureOtpChallenge::query()->lockForUpdate()->findOrFail($challenge->id);
            abort_unless($locked->state === SignatureOtpChallenge::STATE_PENDING_SEND, 409, 'Challenge OTP tidak dapat ditandai terkirim.');
            $locked->update(['state' => SignatureOtpChallenge::STATE_SENT, 'sent_at' => now()]);

            return $locked->fresh();
        }, 3);
    }

    /**
     * @param  array{document_version_id:int, pdf_hash:string, manifest_draft_hash:string, session_id:string, action?:string}  $context
     */
    public function verifyAndConsume(User $user, Document $document, string $otp, array $context): SignatureOtpChallenge
    {
        $this->assertAuthorizedSigner($document, $user);
        $this->assertContext($document, $context);
        $action = $context['action'] ?? (string) config('tte.otp.action');

        $result = DB::transaction(function () use ($user, $document, $otp, $context, $action) {
            $challenge = SignatureOtpChallenge::query()
                ->where('active_binding_key', $this->activeBindingKey($user->id, $document->id, $context['document_version_id'], $action))
                ->lockForUpdate()
                ->first();

            abort_unless($challenge, 422, 'OTP tidak valid, sudah dipakai, dicabut, atau kedaluwarsa.');

            if ($challenge->state !== SignatureOtpChallenge::STATE_SENT) {
                abort(422, 'OTP belum siap digunakan atau sudah tidak aktif.');
            }

            if (now()->greaterThanOrEqualTo($challenge->expires_at)) {
                $this->transitionToTerminal($challenge, SignatureOtpChallenge::STATE_EXPIRED, 'expired');

                return ['challenge' => null, 'error' => 'OTP sudah kedaluwarsa.'];
            }

            $bindingMatches = hash_equals($challenge->pdf_hash, strtolower($context['pdf_hash']))
                && hash_equals($challenge->manifest_draft_hash, strtolower($context['manifest_draft_hash']))
                && hash_equals($challenge->session_id_hash, $this->sessionHash($context['session_id']))
                && hash_equals($challenge->destination_hash, hash_hmac('sha256', $this->normalizeDestination($user->otpDeliveryEmail()), $this->secrets->destinationKey()))
                && $challenge->user_id === (int) $user->id
                && $challenge->document_id === (int) $document->id
                && $challenge->document_version_id === (int) $context['document_version_id']
                && $challenge->signing_ceremony_id === $context['signing_ceremony_id']
                && $challenge->action === $action;

            if (! $bindingMatches) {
                $this->transitionToTerminal($challenge, SignatureOtpChallenge::STATE_REVOKED, 'binding_changed');

                return ['challenge' => null, 'error' => 'Konteks dokumen atau sesi berubah; mintalah OTP baru.'];
            }

            if (! $this->verifier->verify($challenge->uuid, $otp, $challenge->otp_verifier)) {
                $challenge->attempt_count++;
                if ($challenge->attempt_count >= $challenge->max_attempts) {
                    $challenge->state = SignatureOtpChallenge::STATE_LOCKED;
                    $challenge->locked_at = now();
                    $challenge->active_binding_key = null;
                    $challenge->failure_reason = 'max_attempts';
                }
                $challenge->save();

                return ['challenge' => null, 'error' => 'OTP tidak valid.'];
            }

            $now = now();
            $challenge->update([
                'attempt_count' => $challenge->attempt_count + 1,
                'state' => SignatureOtpChallenge::STATE_CONSUMED,
                'verified_at' => $now,
                'consumed_at' => $now,
                'active_binding_key' => null,
                'failure_reason' => null,
            ]);

            return ['challenge' => $challenge->fresh(), 'error' => null];
        }, 3);

        if ($result['error'] !== null) {
            $this->securityEvents->report('otp_verification_failed', [
                'actor_id' => $user->id,
                'document_id' => $document->id,
                'reason' => $result['error'],
            ], 'warning');
            abort(422, $result['error']);
        }

        $this->securityEvents->report('otp_consumed', [
            'actor_id' => $user->id,
            'challenge_id' => $result['challenge']->uuid,
            'document_id' => $document->id,
        ]);

        return $result['challenge'];
    }

    public function revokeActive(User|int $user, ?Document $document = null, string $reason = 'security_context_changed'): int
    {
        $userId = $user instanceof User ? $user->id : $user;

        return DB::transaction(function () use ($userId, $document, $reason) {
            $query = SignatureOtpChallenge::query()
                ->where('user_id', $userId)
                ->whereNotNull('active_binding_key')
                ->lockForUpdate();
            if ($document) {
                $query->where('document_id', $document->id);
            }

            $challenges = $query->get();
            foreach ($challenges as $challenge) {
                $this->transitionToTerminal($challenge, SignatureOtpChallenge::STATE_REVOKED, $reason);
            }

            return $challenges->count();
        }, 3);
    }

    public function revokeForDocument(Document|int $document, string $reason = 'document_context_changed'): int
    {
        $documentId = $document instanceof Document ? $document->id : $document;

        return DB::transaction(function () use ($documentId, $reason) {
            $challenges = SignatureOtpChallenge::query()
                ->where('document_id', $documentId)
                ->whereNotNull('active_binding_key')
                ->lockForUpdate()
                ->get();
            foreach ($challenges as $challenge) {
                $this->transitionToTerminal($challenge, SignatureOtpChallenge::STATE_REVOKED, $reason);
            }

            return $challenges->count();
        }, 3);
    }

    public function markSealed(SignatureOtpChallenge $challenge): SignatureOtpChallenge
    {
        return DB::transaction(function () use ($challenge) {
            $locked = SignatureOtpChallenge::query()->lockForUpdate()->findOrFail($challenge->id);
            abort_unless($locked->state === SignatureOtpChallenge::STATE_CONSUMED && $locked->consumed_at, 409, 'Receipt OTP belum dikonsumsi.');
            $locked->update(['sealed_at' => $locked->sealed_at ?? now()]);

            return $locked->fresh();
        }, 3);
    }

    public function expire(): int
    {
        return DB::transaction(function () {
            $challenges = SignatureOtpChallenge::query()
                ->whereNotNull('active_binding_key')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get();
            foreach ($challenges as $challenge) {
                $this->transitionToTerminal($challenge, SignatureOtpChallenge::STATE_EXPIRED, 'expired');
            }

            return $challenges->count();
        }, 3);
    }

    private function markSendFailed(SignatureOtpChallenge $challenge): void
    {
        DB::transaction(function () use ($challenge) {
            $locked = SignatureOtpChallenge::query()->lockForUpdate()->find($challenge->id);
            if ($locked?->active_binding_key) {
                $this->transitionToTerminal($locked, SignatureOtpChallenge::STATE_SEND_FAILED, 'notification_failed');
            }
        }, 3);
    }

    private function transitionToTerminal(SignatureOtpChallenge $challenge, string $state, string $reason): void
    {
        $attributes = [
            'state' => $state,
            'active_binding_key' => null,
            'failure_reason' => $reason,
        ];
        if ($state === SignatureOtpChallenge::STATE_REVOKED) {
            $attributes['revoked_at'] = now();
        }
        $challenge->update($attributes);
    }

    private function enforcePersistentRateLimit(User $user, Document $document, $now): void
    {
        $base = SignatureOtpChallenge::query()
            ->where('user_id', $user->id)
            ->where('document_id', $document->id);

        $windowCount = (clone $base)->where('requested_at', '>=', $now->copy()->subMinutes((int) config('tte.otp.send_window_minutes')))->count();
        abort_if($windowCount >= (int) config('tte.otp.max_sends_per_window'), 429, 'Batas pengiriman OTP tercapai. Coba kembali nanti.');

        $dailyCount = (clone $base)->where('requested_at', '>=', $now->copy()->subDay())->count();
        abort_if($dailyCount >= (int) config('tte.otp.max_sends_per_day'), 429, 'Batas harian pengiriman OTP tercapai.');
    }

    private function generateOtp(): string
    {
        $digits = (int) config('tte.otp.digits');
        $maximum = (10 ** $digits) - 1;

        return str_pad((string) random_int(0, $maximum), $digits, '0', STR_PAD_LEFT);
    }

    private function activeBindingKey(int $userId, int $documentId, int $versionId, string $action): string
    {
        return hash('sha256', implode('|', [$userId, $documentId, $versionId, $action]));
    }

    private function sessionHash(string $sessionId): string
    {
        abort_if($sessionId === '', 422, 'Sesi signing tidak tersedia.');

        return hash('sha256', $sessionId);
    }

    private function normalizeDestination(?string $email): string
    {
        $normalized = strtolower(trim((string) $email));
        abort_unless(filter_var($normalized, FILTER_VALIDATE_EMAIL), 422, 'Email organisasi penandatangan tidak valid.');

        return $normalized;
    }

    private function maskDestination(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }

    private function optionalMetadataHash(?string $value): ?string
    {
        return $value === null || $value === '' ? null : hash('sha256', $value);
    }

    /** @param array<string, mixed> $context */
    private function assertContext(Document $document, array $context): void
    {
        foreach (['document_version_id', 'pdf_hash', 'manifest_draft_hash', 'session_id', 'signing_ceremony_id'] as $required) {
            abort_unless(array_key_exists($required, $context), 422, "Konteks signing tidak lengkap: {$required}.");
        }
        abort_unless((int) $context['document_version_id'] === (int) $document->currentVersion?->id, 422, 'Versi dokumen berubah.');
        abort_unless(preg_match('/^[a-f0-9]{64}$/i', (string) $context['pdf_hash']) === 1, 422, 'Hash PDF kandidat tidak valid.');
        abort_unless(preg_match('/^[a-f0-9]{64}$/i', (string) $context['manifest_draft_hash']) === 1, 422, 'Hash manifest draft tidak valid.');
        $reauthenticationAge = $context['reauthentication_age_seconds'] ?? null;
        abort_if(! is_int($reauthenticationAge) || $reauthenticationAge < 0 || $reauthenticationAge > (int) config('tte.otp.reauthentication_max_age_seconds'), 423, 'Konfirmasi ulang password diperlukan untuk signing.');
    }

    private function assertAuthorizedSigner(Document $document, User $user): void
    {
        abort_unless($user->is_active, 403, 'Akun penandatangan tidak aktif.');
        abort_unless($document->status === Document::STATUS_MENUNGGU_TTD, 403, 'Dokumen tidak dalam status menunggu tanda tangan.');

        $roles = $user->getRoleNames()->all();
        if ($delegation = $user->activeDelegation()) {
            $roles = array_unique(array_merge($roles, $delegation->pejabat?->getRoleNames()->all() ?? []));
        }

        $authorized = $document->workflowTemplate?->steps()
            ->where('tipe', 'penandatangan')
            ->whereIn('role_nama', $roles)
            ->exists() ?? false;
        abort_unless($authorized, 403, 'Anda bukan penandatangan yang sah untuk dokumen ini.');
    }
}
