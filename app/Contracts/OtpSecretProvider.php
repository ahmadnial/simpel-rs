<?php

namespace App\Contracts;

interface OtpSecretProvider
{
    public function otpKey(): string;

    public function destinationKey(): string;
}
