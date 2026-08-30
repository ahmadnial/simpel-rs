<?php

namespace App\Contracts;

use App\Support\EvidenceSignature;
use App\Support\SigningKeyDescriptor;

interface EvidenceSigner
{
    public function activeKey(): SigningKeyDescriptor;

    public function sign(string $canonicalManifest): EvidenceSignature;
}
