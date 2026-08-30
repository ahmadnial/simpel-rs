<?php

namespace App\Contracts;

interface OtpVerifier
{
    public function make(string $challengeUuid, string $otp): string;

    public function verify(string $challengeUuid, string $otp, string $verifier): bool;
}
