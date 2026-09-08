<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CloseOverlappingVerifications extends Command
{
    protected $signature = 'verifikasi:close-overlaps {document : ID dokumen} {--apply : Simpan pembatalan; default hanya pratinjau}';

    protected $description = 'Tutup tiket menunggu yang penerimanya sudah ditugaskan di level lebih rendah pada versi sama';

    public function handle(): int
    {
        return DB::transaction(function () {
            $document = Document::with('currentVersion')->lockForUpdate()->findOrFail($this->argument('document'));
            $tickets = $document->verifications()->with('workflowStep')->lockForUpdate()->get();
            $overlaps = $tickets->filter(fn ($ticket) => $ticket->isMenunggu() && $ticket->hasLowerLevelAssignment());
            $this->table(['Tiket', 'Level', 'Penerima'], $overlaps->map(fn ($ticket) => [$ticket->id, $ticket->level, $ticket->verifikator_id])->all());

            // Jangan menutup satu-satunya penerima tersisa atau membuat kuorum mustahil.
            foreach ($overlaps->groupBy(fn ($ticket) => $ticket->document_version_id.':'.$ticket->workflow_step_id.':'.$ticket->verification_round) as $group) {
                $target = $group->first();
                if ($target->document_version_id !== $document->currentVersion?->id || $target->level !== $document->current_step) {
                    continue;
                }
                $remaining = $tickets->filter(fn ($ticket) => $ticket->document_version_id === $target->document_version_id
                    && $ticket->workflow_step_id === $target->workflow_step_id
                    && $ticket->verification_round === $target->verification_round
                    && ! $ticket->hasLowerLevelAssignment()
                    && ($ticket->isMenunggu() || $ticket->isApproved())
                )->unique('verifikator_id')->count();
                $required = $target->workflowStep?->isParallelQuorum() ? max(1, (int) $target->workflowStep->min_approval) : 1;
                if ($remaining < $required) {
                    $this->error('Pemeriksa berbeda tidak mencukupi. Tidak ada tiket yang diubah; perbaiki penugasan terlebih dahulu.');

                    return self::FAILURE;
                }
            }

            if (! $this->option('apply')) {
                $this->info('Pratinjau saja. Gunakan --apply untuk menyimpan.');

                return self::SUCCESS;
            }
            foreach ($overlaps as $ticket) {
                $ticket->update([
                    'status' => DocumentVerification::STATUS_DIBATALKAN,
                    'direset_alasan' => 'Penugasan rangkap ditutup: penerima sudah ditugaskan pada level sebelumnya untuk versi dokumen yang sama.',
                    'direset_at' => now(),
                ]);
                AuditLog::catat('koreksi_penugasan_verifikasi', "Tiket {$ticket->id} ditutup karena penugasan rangkap antarlevel.", $document);
            }
            $this->info($overlaps->count().' tiket ditutup. Keputusan terdahulu tetap tersimpan.');

            return self::SUCCESS;
        });
    }
}
