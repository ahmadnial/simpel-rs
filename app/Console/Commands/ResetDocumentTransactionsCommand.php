<?php

namespace App\Console\Commands;

use App\Notifications\DokumenNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ResetDocumentTransactionsCommand extends Command
{
    private const ACKNOWLEDGEMENT = 'HAPUS-TRANSAKSI-PERSURATAN';

    protected $signature = 'data:reset-document-transactions
        {--execute : Jalankan penghapusan; tanpa opsi ini hanya menampilkan dry-run}
        {--acknowledge= : Wajib diisi HAPUS-TRANSAKSI-PERSURATAN untuk eksekusi production}
        {--ticket= : Nomor tiket/change request untuk jejak operasional}';

    protected $description = 'Reset transaksi persuratan tanpa menghapus master, audit keamanan, signing key, atau objek WORM';

    /** @var list<string> */
    private array $transactionTables = [
        'evidence_status_events',
        'evidence_storage_copies',
        'document_signatures',
        'signing_outbox_messages',
        'signature_evidence',
        'signing_ceremonies',
        'signature_otp_challenges',
        'document_distributions',
        'document_verifications',
        'document_versions',
        'documents',
        'numbering_sequences',
    ];

    public function handle(): int
    {
        $stats = collect($this->transactionTables)
            ->filter(fn (string $table): bool => Schema::hasTable($table))
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
        $documentNotifications = Schema::hasTable('notifications')
            ? DB::table('notifications')->where('type', DokumenNotification::class)->count()
            : 0;

        $this->table(['Data transaksi', 'Jumlah'], [
            ...$stats->map(fn (int $count, string $table): array => [$table, $count])->values()->all(),
            ['notifications (khusus dokumen)', $documentNotifications],
        ]);
        $this->info('Dipertahankan: master, role/permission, delegasi, signing_keys, audit_logs, audit_chain_events/streams/checkpoints, serta objek MinIO WORM.');

        if (! $this->option('execute')) {
            $this->warn('DRY-RUN saja. Tidak ada data yang diubah.');

            return self::SUCCESS;
        }

        $ticket = trim((string) $this->option('ticket'));
        if ($ticket === '') {
            $this->error('Eksekusi ditolak: --ticket wajib diisi.');

            return self::FAILURE;
        }

        if (app()->environment('production') && $this->option('acknowledge') !== self::ACKNOWLEDGEMENT) {
            $this->error('Eksekusi production ditolak: acknowledgement tidak cocok.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($ticket): void {
                if (Schema::hasTable('notifications')) {
                    DB::table('notifications')->where('type', DokumenNotification::class)->delete();
                }

                if (Schema::hasColumn('users', 'otp_document_id')) {
                    $legacyOtp = ['otp_document_id' => null];
                    if (Schema::hasColumn('users', 'otp_hash')) {
                        $legacyOtp['otp_hash'] = null;
                    }
                    DB::table('users')->whereNotNull('otp_document_id')->update($legacyOtp);
                }

                foreach ($this->transactionTables as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }

                if (Schema::hasTable('audit_logs')) {
                    DB::table('audit_logs')->insert([
                        'user_id' => null,
                        'user_name' => 'System',
                        'aksi' => 'reset_transaksi_persuratan',
                        'model_type' => null,
                        'model_id' => null,
                        'deskripsi' => "Transaksi persuratan direset melalui change ticket {$ticket}; data master dan audit dipertahankan.",
                        'data_lama' => null,
                        'data_baru' => json_encode(['ticket' => $ticket], JSON_THROW_ON_ERROR),
                        'ip_address' => null,
                        'user_agent' => 'artisan:data:reset-document-transactions',
                        'created_at' => now(),
                    ]);
                }
            }, 3);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Reset dibatalkan dan transaksi database di-roll back: '.$exception->getMessage());
            $this->warn('Jika error berupa DENY DELETE, jalankan command dengan credential DBA/migration yang terpisah; jangan mencabut proteksi principal aplikasi.');

            return self::FAILURE;
        }

        $remaining = collect($this->transactionTables)
            ->filter(fn (string $table): bool => Schema::hasTable($table))
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
        if ($remaining->contains(fn (int $count): bool => $count !== 0)) {
            $this->error('Verifikasi pasca-reset gagal: masih ada tabel transaksi yang tidak kosong.');

            return self::FAILURE;
        }

        $this->info("Reset transaksi persuratan selesai. Ticket: {$ticket}");
        $this->warn('File lokal belum dihapus oleh command ini. Objek MinIO WORM sengaja tidak dihapus.');

        return self::SUCCESS;
    }
}
