<?php

namespace App\Services;

use App\Contracts\OtpSecretProvider;
use RuntimeException;

class RuntimeOtpSecretProvider implements OtpSecretProvider
{
    public function otpKey(): string
    {
        return $this->validateSecret(config('tte.otp.secret'), 'TTE_OTP_HMAC_SECRET');
    }

    public function destinationKey(): string
    {
        return $this->validateSecret(config('tte.otp.destination_secret'), 'TTE_OTP_DESTINATION_HMAC_SECRET');
    }

    private function validateSecret(mixed $value, string $name): string
    {
        if (! is_string($value) || strlen($value) < 32) {
            throw new RuntimeException("Secret runtime {$name} tidak tersedia atau terlalu pendek; signing OTP ditutup demi keamanan.");
        }

        return $value;
    }
}
