<?php

namespace App\Support;

readonly class EvidenceSignature
{
    public function __construct(
        public SigningKeyDescriptor $key,
        public string $signature,
    ) {}
}
