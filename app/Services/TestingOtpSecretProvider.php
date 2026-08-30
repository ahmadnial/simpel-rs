<?php

namespace App\Services;

use App\Contracts\OtpSecretProvider;
use LogicException;

class TestingOtpSecretProvider implements OtpSecretProvider
{
    public function __construct()
    {
        if (! app()->environment('testing')) {
            throw new LogicException('TestingOtpSecretProvider hanya boleh digunakan oleh automated test.');
        }
    }

    public function otpKey(): string
    {
        return 'testing-only-otp-hmac-key-not-for-runtime-use';
    }

    public function destinationKey(): string
    {
        return 'testing-only-destination-key-distinct-from-otp';
    }
}
