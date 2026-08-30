<?php

namespace App\Console\Commands;

use App\Models\SignatureEvidence;
use App\Services\EvidenceStatusService;
use Illuminate\Console\Command;

class SetEvidenceStatusCommand extends Command
{
    protected $signature = 'evidence:set-status {uuid} {status : revoked|superseded} {--reason=} {--reference=} {--related=} {--force}';

    protected $description = 'Catat revocation/supersede administratif tanpa mengubah bukti historis';

    public function handle(EvidenceStatusService $statuses): int
    {
        if (! $this->option('force') && ! $this->confirm('Catat status administratif append-only untuk evidence ini?')) {
            return self::SUCCESS;
        }
        try {
            $evidence = SignatureEvidence::where('uuid', $this->argument('uuid'))->firstOrFail();
            $event = $statuses->record(
                $evidence,
                (string) $this->argument('status'),
                (string) $this->option('reason'),
                (string) $this->option('reference'),
                $this->option('related') ? (string) $this->option('related') : null,
                auth()->id(),
            );
            $this->info("Status {$event->status} tercatat; event {$event->uuid}. Bukti lama tidak diubah.");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'Status evidence gagal dicatat. Periksa log keamanan.');

            return self::FAILURE;
        }
    }
}
