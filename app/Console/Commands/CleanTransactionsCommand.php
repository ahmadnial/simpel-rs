<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\Models\User;
use App\Models\Unit;
use App\Models\DocumentType;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowStep;

class CleanTransactionsCommand extends Command
{
    protected $signature = 'data:clean-transactions {--force : Eksekusi tanpa konfirmasi}';
    protected $description = 'Membersihkan seluruh data transaksi dan dokumen tanpa menghapus data master';

    public function handle(): int
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->error('Perintah data:clean-transactions hanya diizinkan pada environment local/testing.');

            return Command::FAILURE;
        }

        $this->info('=====================================================');
        $this->info(' PEMBERSIHAN DATA TRANSAKSI & DOKUMEN (SIMPEL-RS)');
        $this->info('=====================================================');

        if (!$this->option('force') && !$this->confirm('Apakah Anda yakin ingin menghapus seluruh data transaksi dokumen? Data Master TIDAK akan dihapus.')) {
            $this->warn('Operasi dibatalkan.');
            return Command::SUCCESS;
        }

        $this->info('Memulai proses pembersihan...');

        // 1. Catat statistik master sebelum pembersihan
        $masterStats = [
            'users'             => User::count(),
            'units'             => Unit::count(),
            'document_types'    => DocumentType::count(),
            'workflow_templates'=> WorkflowTemplate::count(),
            'workflow_steps'    => WorkflowStep::count(),
        ];

        DB::beginTransaction();
        try {
            foreach ([
                'evidence_status_events', 'evidence_storage_copies', 'audit_checkpoints', 'audit_chain_events', 'audit_chain_streams',
                'document_signatures', 'signing_outbox_messages', 'signature_evidence', 'signing_ceremonies',
                'signature_otp_challenges',
            ] as $table) {
                if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            // Hapus tabel transaksi secara berurutan sesuai relasi Foreign Key
            $this->line('1. Menghapus data distribusi dokumen...');
            DB::table('document_distributions')->delete();

            $this->line('2. Menghapus data tanda tangan elektronik (TTE)...');
            // Tabel signature/evidence v2 sudah dibersihkan lebih dahulu untuk menjaga urutan FK.

            $this->line('3. Menghapus data verifikasi dokumen...');
            DB::table('document_verifications')->delete();

            $this->line('4. Menghapus riwayat versi dokumen...');
            DB::table('document_versions')->delete();

            $this->line('5. Menghapus naskah dinas & dokumen...');
            DB::table('documents')->delete();

            $this->line('6. Mereset nomor urut sequence penomoran surat...');
            DB::table('numbering_sequences')->delete();

            $this->line('7. Menghapus notifikasi lama...');
            DB::table('notifications')->delete();

            $this->line('8. Menghapus audit log aktivitas dokumen...');
            DB::table('audit_logs')->delete();

            DB::commit();
            $this->info('✓ Data transaksi di database berhasil dibersihkan.');
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Gagal membersihkan database: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // 2. Pembersihan File Fisik di Storage
        $this->line('9. Membersihkan file fisik dokumen dan cache PDF...');
        $pathsToClean = [
            storage_path('app/private/documents'),
            storage_path('app/private/pdf_cache'),
            storage_path('app/private/temp_processed'),
        ];

        foreach ($pathsToClean as $path) {
            if (File::exists($path)) {
                File::cleanDirectory($path);
                $this->line("   - Dibersihkan: {$path}");
            }
        }

        // Hapus folder temporary libreoffice profile
        $tempProfiles = File::directories(storage_path('app/private'));
        foreach ($tempProfiles as $dir) {
            if (str_contains(basename($dir), 'soffice_prof_')) {
                File::deleteDirectory($dir);
            }
        }

        // 3. Verifikasi Data Master Tetap Utuh
        $this->info('');
        $this->info('=====================================================');
        $this->info(' VERIFIKASI KEUTUHAN DATA MASTER:');
        $this->info('=====================================================');
        $this->table(
            ['Data Master', 'Sebelum', 'Setelah', 'Status'],
            [
                ['Pengguna (users)', $masterStats['users'], User::count(), User::count() === $masterStats['users'] ? '✅ UTUH' : '❌ BERUBAH'],
                ['Unit & Instalasi (units)', $masterStats['units'], Unit::count(), Unit::count() === $masterStats['units'] ? '✅ UTUH' : '❌ BERUBAH'],
                ['Jenis Naskah (document_types)', $masterStats['document_types'], DocumentType::count(), DocumentType::count() === $masterStats['document_types'] ? '✅ UTUH' : '❌ BERUBAH'],
                ['Template Workflow', $masterStats['workflow_templates'], WorkflowTemplate::count(), WorkflowTemplate::count() === $masterStats['workflow_templates'] ? '✅ UTUH' : '❌ BERUBAH'],
                ['Tahapan Workflow', $masterStats['workflow_steps'], WorkflowStep::count(), WorkflowStep::count() === $masterStats['workflow_steps'] ? '✅ UTUH' : '❌ BERUBAH'],
            ]
        );

        $this->info('=====================================================');
        $this->info(' HASIL PEMBERSIHAN DATA TRANSAKSI:');
        $this->info('=====================================================');
        $this->table(
            ['Tabel Transaksi', 'Jumlah Sisa Data', 'Status'],
            [
                ['documents', DB::table('documents')->count(), DB::table('documents')->count() === 0 ? '✅ BERSIH (0)' : '⚠️'],
                ['document_versions', DB::table('document_versions')->count(), DB::table('document_versions')->count() === 0 ? '✅ BERSIH (0)' : '⚠️'],
                ['document_verifications', DB::table('document_verifications')->count(), DB::table('document_verifications')->count() === 0 ? '✅ BERSIH (0)' : '⚠️'],
                ['document_signatures', DB::table('document_signatures')->count(), DB::table('document_signatures')->count() === 0 ? '✅ BERSIH (0)' : '⚠️'],
                ['document_distributions', DB::table('document_distributions')->count(), DB::table('document_distributions')->count() === 0 ? '✅ BERSIH (0)' : '⚠️'],
                ['numbering_sequences', DB::table('numbering_sequences')->count(), DB::table('numbering_sequences')->count() === 0 ? '✅ BERSIH (0)' : '⚠️'],
                ['notifications', DB::table('notifications')->count(), DB::table('notifications')->count() === 0 ? '✅ BERSIH (0)' : '⚠️'],
                ['audit_logs', DB::table('audit_logs')->count(), DB::table('audit_logs')->count() === 0 ? '✅ BERSIH (0)' : '⚠️'],
            ]
        );

        $this->info('🎉 Pembersihan selesai sempurna! Sistem siap digunakan untuk pengujian baru.');
        return Command::SUCCESS;
    }
}
