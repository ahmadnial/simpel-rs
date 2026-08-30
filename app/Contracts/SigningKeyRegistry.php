<?php

namespace App\Contracts;

use App\Support\SigningKeyDescriptor;

interface SigningKeyRegistry
{
    public function active(): SigningKeyDescriptor;

    public function find(string $keyId): ?SigningKeyDescriptor;
}
