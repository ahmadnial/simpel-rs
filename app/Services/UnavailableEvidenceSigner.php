<?php

namespace App\Services;

use App\Contracts\EvidenceSigner;
use App\Support\EvidenceSignature;
use App\Support\SigningKeyDescriptor;
use RuntimeException;

class UnavailableEvidenceSigner implements EvidenceSigner
{
    public function activeKey(): SigningKeyDescriptor
    {
        throw new RuntimeException('Provider KMS/HSM/Vault untuk evidence signer belum dikonfigurasi; profile v2 ditutup.');
    }

    public function sign(string $canonicalManifest): EvidenceSignature
    {
        throw new RuntimeException('Provider KMS/HSM/Vault untuk evidence signer belum dikonfigurasi; profile v2 ditutup.');
    }
}
