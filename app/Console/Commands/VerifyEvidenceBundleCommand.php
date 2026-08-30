<?php

namespace App\Console\Commands;

use App\Services\EvidenceVerificationService;
use Illuminate\Console\Command;
use Throwable;

class VerifyEvidenceBundleCommand extends Command
{
    protected $signature = 'evidence:verify-bundle {path} {--public-key= : File JSON public key dari kanal resmi} {--json : Output machine-readable}';

    protected $description = 'Verifikasi evidence bundle SIMPEL-RS tanpa akses database';

    public function handle(EvidenceVerificationService $verifier): int
    {
        try {
            $path = (string) $this->argument('path');
            if (! is_file($path)) {
                throw new \InvalidArgumentException('Bundle tidak ditemukan.');
            }
            $trustedKey = null;
            if ($keyPath = $this->option('public-key')) {
                $trustedKey = json_decode(file_get_contents((string) $keyPath), true, flags: JSON_THROW_ON_ERROR);
            }
            $result = $verifier->verifyBundle($path, $trustedKey);
            $output = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->line($this->option('json') ? $output : ($result['valid'] ? 'VALID: '.$output : 'INVALID: '.$output));

            return $result['valid'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $result = ['valid' => false, 'error' => 'input_error'];
            $this->line($this->option('json') ? json_encode($result, JSON_THROW_ON_ERROR) : 'ERROR: input tidak dapat diverifikasi.');

            return self::INVALID;
        }
    }
}
