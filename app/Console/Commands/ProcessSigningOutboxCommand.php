<?php

namespace App\Console\Commands;

use App\Models\SigningOutboxMessage;
use App\Services\DocumentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessSigningOutboxCommand extends Command
{
    protected $signature = 'tte:process-signing-outbox {--limit=25 : Maksimum pesan yang diproses}';

    protected $description = 'Pulihkan finalisasi pengesahan yang tertunda setelah OTP berhasil diverifikasi';

    public function handle(DocumentService $documents): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $processed = 0;
        $failed = 0;

        for ($iteration = 0; $iteration < $limit; $iteration++) {
            $messageId = SigningOutboxMessage::query()
                ->where('type', 'finalize_signature_evidence')
                ->where(function ($query): void {
                    $query->where(function ($pending): void {
                        $pending->where('state', 'pending')->where('available_at', '<=', now());
                    })->orWhere(function ($stale): void {
                        $stale->where('state', 'processing')->where('updated_at', '<=', now()->subMinutes(10));
                    });
                })
                ->orderBy('id')
                ->value('id');

            if (! $messageId) {
                break;
            }

            $message = DB::transaction(function () use ($messageId) {
                $locked = SigningOutboxMessage::query()->lockForUpdate()->find($messageId);
                if (! $locked) {
                    return null;
                }

                $claimable = ($locked->state === 'pending' && $locked->available_at->lte(now()))
                    || ($locked->state === 'processing' && $locked->updated_at->lte(now()->subMinutes(10)));
                if (! $claimable) {
                    return null;
                }

                $locked->update([
                    'state' => 'processing',
                    'attempt_count' => $locked->attempt_count + 1,
                    'failed_at' => null,
                    'last_error' => null,
                ]);

                return $locked->fresh(['ceremony']);
            }, 3);

            if (! $message) {
                continue;
            }

            try {
                abort_unless($message->ceremony, 409, 'Ceremony outbox tidak ditemukan.');
                $documents->resumeFinalization($message->ceremony);
                $message->fresh()->update([
                    'state' => 'processed',
                    'processed_at' => now(),
                    'failed_at' => null,
                    'last_error' => null,
                ]);
                $processed++;
            } catch (Throwable $exception) {
                report($exception);
                $attempts = $message->fresh()->attempt_count;
                $terminal = $attempts >= 10;
                $message->update([
                    'state' => $terminal ? 'failed' : 'pending',
                    'available_at' => now()->addMinutes(min(60, 2 ** min($attempts, 6))),
                    'failed_at' => $terminal ? now() : null,
                    'last_error' => class_basename($exception).' ['.substr(hash('sha256', $exception->getMessage()), 0, 12).']',
                ]);
                $failed++;
            }
        }

        $this->info("Outbox pengesahan: {$processed} selesai, {$failed} dijadwalkan ulang/gagal.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
