<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowStandardizationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_clones_pedoman_for_each_active_classification_without_mutating_old_templates(): void
    {
        $pedomanType = $this->type('Pedoman', 'PED');
        $targetType = $this->type('Panduan', 'PAD');
        $noWorkflowType = $this->type('Laporan', 'LAP');

        $source = WorkflowTemplate::create([
            'nama' => 'Pedoman', 'document_type_id' => $pedomanType->id,
            'is_active' => true, 'is_default' => true,
        ]);
        $first = WorkflowStep::create([
            'workflow_template_id' => $source->id, 'urutan' => 1,
            'nama_tahap' => 'Verifikasi Asesor Internal', 'tipe' => 'verifikasi',
            'mode_verifikasi' => 'parallel', 'min_approval' => 1, 'sla_hari_kerja' => 2,
        ]);
        $first->verifierPool()->create(['tipe_pool' => 'role', 'role_nama' => 'asesor_internal']);
        WorkflowStep::create([
            'workflow_template_id' => $source->id, 'urutan' => 2,
            'nama_tahap' => 'Verifikasi Sekretariat', 'tipe' => 'verifikasi',
            'role_nama' => 'verifikator', 'mode_verifikasi' => 'serial', 'min_approval' => 1, 'sla_hari_kerja' => 2,
        ]);
        WorkflowStep::create([
            'workflow_template_id' => $source->id, 'urutan' => 3,
            'nama_tahap' => 'Penandatangan', 'tipe' => 'penandatangan',
            'role_nama' => 'penandatangan', 'mode_verifikasi' => 'serial', 'min_approval' => 1, 'sla_hari_kerja' => 2,
        ]);

        $old = WorkflowTemplate::create([
            'nama' => 'Alur Lama Panduan', 'document_type_id' => $targetType->id,
            'is_active' => true, 'is_default' => true,
        ]);

        $this->artisan('workflow:standardize-from-pedoman --apply')
            ->assertSuccessful();

        $this->assertFalse($old->fresh()->is_active);
        $this->assertFalse($old->fresh()->is_default);

        foreach ([$targetType, $noWorkflowType] as $type) {
            $standard = WorkflowTemplate::where('document_type_id', $type->id)
                ->where('is_default', true)->firstOrFail();
            $this->assertTrue($standard->is_active);
            $this->assertSame('Standar Pedoman - ' . $type->nama, $standard->nama);
            $this->assertSame(3, $standard->steps()->count());
            $this->assertSame('parallel', $standard->steps()->where('urutan', 1)->value('mode_verifikasi'));
            $this->assertSame(1, $standard->steps()->where('urutan', 1)->first()->verifierPool()->count());
        }

        $this->assertSame(3, $source->fresh()->steps()->count());
    }

    private function type(string $name, string $code): DocumentType
    {
        return DocumentType::create([
            'nama' => $name, 'kode' => $code, 'singkatan' => $code,
            'format_nomor' => '{urut}/' . $code . '/{tahun}', 'is_active' => true, 'urutan' => 1,
        ]);
    }
}
