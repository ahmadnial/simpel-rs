<?php

namespace Tests\Support;

use App\Models\Document;
use App\Models\User;
use App\Notifications\OtpTandaTangan;
use App\Services\DocumentService;
use App\Services\SigningOtpService;
use Illuminate\Support\Facades\Notification;

trait RequestsSigningOtp
{
    /**
     * @return array{otp:string,session_id:string,context:array<string,mixed>}
     */
    protected function requestSigningOtp(User $user, Document $document, string $sessionId = 'automated-test-session'): array
    {
        Notification::fake();
        $documentService = app(DocumentService::class);
        $context = $documentService->prepareOtpContext($document, $user, $sessionId);
        $challenge = app(SigningOtpService::class)->request($user, $document, $context);

        $otp = null;
        Notification::assertSentTo(
            $user,
            OtpTandaTangan::class,
            function (OtpTandaTangan $notification) use ($challenge, &$otp): bool {
                if ($notification->challengeId !== substr($challenge->uuid, 0, 8)) {
                    return false;
                }
                $otp = $notification->otp;

                return true;
            }
        );

        return ['otp' => $otp, 'session_id' => $sessionId, 'context' => $context];
    }
}
