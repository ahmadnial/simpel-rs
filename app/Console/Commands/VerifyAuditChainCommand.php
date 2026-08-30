<?php

namespace App\Console\Commands;

use App\Services\AuditChainVerifier;
use App\Services\SecurityEventReporter;
use Illuminate\Console\Command;

class VerifyAuditChainCommand extends Command
{
    protected $signature = 'tte:audit-verify {--stream=global} {--json}';

    protected $description = 'Verifikasi audit hash-chain dan kirim alert bila ditemukan gap/tamper';

    public function handle(AuditChainVerifier $verifier, SecurityEventReporter $events): int
    {
        $stream = (string) $this->option('stream');
        $result = $verifier->verify($stream);
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line($result['valid'] ? 'Audit chain valid.' : 'Audit chain TIDAK valid: '.implode(', ', $result['errors']));
        }
        if (! $result['valid']) {
            $events->report('audit_chain_verification_failed', ['stream_id' => $stream, 'errors' => $result['errors']], 'critical');
        }

        return $result['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
