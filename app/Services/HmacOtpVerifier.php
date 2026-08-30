<?php

namespace App\Services;

use App\Contracts\OtpSecretProvider;
use App\Contracts\OtpVerifier;

class HmacOtpVerifier implements OtpVerifier
{
    public function __construct(private readonly OtpSecretProvider $secrets) {}

    public function make(string $challengeUuid, string $otp): string
    {
        // SQL Server uniqueidentifier dibaca kembali sebagai uppercase.
        // UUID bersifat case-insensitive, maka bentuk HMAC harus dinormalisasi.
        return hash_hmac('sha256', strtolower($challengeUuid)."\0".$otp, $this->secrets->otpKey());
    }

    public function verify(string $challengeUuid, string $otp, string $verifier): bool
    {
        return hash_equals($verifier, $this->make($challengeUuid, $otp));
    }
}
