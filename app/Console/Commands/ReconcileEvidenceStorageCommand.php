<?php

namespace App\Console\Commands;

use App\Models\SignatureEvidence;
use App\Services\EvidenceStorageService;
use Illuminate\Console\Command;

class ReconcileEvidenceStorageCommand extends Command
{
    protected $signature = 'evidence:reconcile {uuid? : Evidence UUID; kosong berarti semua} {--json}';

    protected $description = 'Read-back dan rekonsiliasi seluruh receipt immutable evidence';

    public function handle(EvidenceStorageService $storage): int
    {
        $query = SignatureEvidence::query()->orderBy('id');
        if ($uuid = $this->argument('uuid')) {
            $query->where('uuid', $uuid);
        }
        $evidence = $query->get();
        if ($evidence->isEmpty()) {
            $this->error('Evidence tidak ditemukan.');

            return self::INVALID;
        }
        $results = [];
        foreach ($evidence as $item) {
            $results[$item->uuid] = $storage->reconcile($item);
        }
        $valid = collect($results)->every(fn (array $result): bool => $result['valid']);
        $this->line($this->option('json')
            ? json_encode(['valid' => $valid, 'evidence' => $results], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            : ($valid ? 'Seluruh evidence storage valid.' : 'Rekonsiliasi menemukan mismatch/gap.'));

        return $valid ? self::SUCCESS : self::FAILURE;
    }
}
