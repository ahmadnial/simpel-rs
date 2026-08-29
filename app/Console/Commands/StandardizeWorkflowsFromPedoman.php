<?php

namespace App\Console\Commands;

use App\Models\DocumentType;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StandardizeWorkflowsFromPedoman extends Command
{
    protected $signature = 'workflow:standardize-from-pedoman {--apply : Terapkan perubahan ke database}';

    protected $description = 'Buat template default setiap klasifikasi aktif dengan konfigurasi dari alur Pedoman.';

    public function handle(): int
    {
        $source = WorkflowTemplate::query()
            ->where('nama', 'Pedoman')
            ->whereHas('documentType', fn ($query) => $query->where('kode', 'PED'))
            ->with('steps.verifierPool')
            ->first();

        if (!$source) {
            $this->error('Template sumber “Pedoman” untuk klasifikasi PED tidak ditemukan.');
            return self::FAILURE;
        }

        $steps = $source->steps->sortBy('urutan')->values();
        if (!$this->sourceIsValid($steps)) {
            $this->error('Template Pedoman tidak valid; tidak ada perubahan yang dilakukan.');
            return self::FAILURE;
        }

        $types = DocumentType::active()->orderBy('nama')->get();
        $targets = $types->reject(fn (DocumentType $type) => (int) $type->id === (int) $source->document_type_id);

        $this->table(['Klasifikasi', 'Nama template baru'], $targets->map(fn (DocumentType $type) => [
            $type->nama,
            $this->standardName($type),
        ])->all());

        if (!$this->option('apply')) {
            $this->warn('Dry run: gunakan --apply untuk membuat template dan menjadikannya default.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($source, $steps, $targets) {
            foreach ($targets as $type) {
                // Jangan menyentuh template lama: dokumen yang sudah diajukan tetap
                // merujuk kepadanya. Hanya pengajuan baru yang akan memilih default baru.
                WorkflowTemplate::where('document_type_id', $type->id)
                    ->where('id', '!=', $source->id)
                    ->update(['is_default' => false, 'is_active' => false]);

                $target = WorkflowTemplate::create([
                    'nama' => $this->standardName($type),
                    'document_type_id' => $type->id,
                    'deskripsi' => 'Template standar yang disalin dari alur persetujuan Pedoman.',
                    'is_default' => true,
                    'is_active' => true,
                ]);

                foreach ($steps as $sourceStep) {
                    $targetStep = $target->steps()->create([
                        'urutan' => $sourceStep->urutan,
                        'nama_tahap' => $sourceStep->nama_tahap,
                        'tipe' => $sourceStep->tipe,
                        'role_nama' => $sourceStep->role_nama,
                        'sla_hari_kerja' => $sourceStep->sla_hari_kerja,
                        'min_approval' => $sourceStep->min_approval,
                        'mode_verifikasi' => $sourceStep->mode_verifikasi,
                    ]);

                    foreach ($sourceStep->verifierPool as $member) {
                        $targetStep->verifierPool()->create([
                            'tipe_pool' => $member->tipe_pool,
                            'user_id' => $member->user_id,
                            'role_nama' => $member->role_nama,
                        ]);
                    }
                }
            }
        });

        $this->info("{$targets->count()} template standar berhasil dibuat dan ditetapkan sebagai default.");
        return self::SUCCESS;
    }

    private function standardName(DocumentType $type): string
    {
        return 'Standar Pedoman - ' . $type->nama;
    }

    private function sourceIsValid($steps): bool
    {
        if ($steps->isEmpty() || $steps->last()->tipe !== 'penandatangan') {
            return false;
        }

        if ($steps->where('tipe', 'penandatangan')->count() !== 1
            || $steps->where('tipe', 'verifikasi')->isEmpty()) {
            return false;
        }

        foreach ($steps as $step) {
            if ($step->mode_verifikasi === 'parallel' && $step->verifierPool->isEmpty()) {
                return false;
            }
            if ($step->mode_verifikasi === 'serial' && !$step->role_nama && $step->urutan > 1) {
                return false;
            }
        }

        return true;
    }
}
