<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TandaTanganHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_signer_history_combines_signed_and_returned_events_without_exposing_other_users_history(): void
    {
        $unit = Unit::create(['nama' => 'Direktorat', 'kode' => 'DIR', 'singkatan' => 'DIR', 'urutan' => 1]);
        $type = DocumentType::create([
            'nama' => 'Kebijakan', 'kode' => 'KBJ', 'singkatan' => 'KBJ',
            'format_nomor' => '{urut}/KBJ/{tahun}', 'level_verifikasi' => 1, 'urutan' => 1,
        ]);
        $signer = User::factory()->create(['name' => 'Penandatangan Utama', 'unit_id' => $unit->id]);
        $otherSigner = User::factory()->create(['name' => 'Penandatangan Lain', 'unit_id' => $unit->id]);
        $proposer = User::factory()->create(['name' => 'Pengusul Dokumen', 'unit_id' => $unit->id]);

        $signedDocument = $this->makeDocument('Kebijakan Sudah Disahkan', $type, $unit, $proposer, Document::STATUS_DITANDATANGANI);
        $version = DocumentVersion::create([
            'document_id' => $signedDocument->id,
            'versi' => 1,
            'file_path' => 'dokumen/source.docx',
            'file_name' => 'source.docx',
            'uploaded_by' => $proposer->id,
            'is_current' => true,
        ]);
        DocumentSignature::create([
            'document_id' => $signedDocument->id,
            'document_version_id' => $version->id,
            'penandatangan_id' => $signer->id,
            'metode_tte' => 'internal',
            'hash_dokumen' => str_repeat('a', 64),
            'qr_token' => 'history-signed-token',
            'ditandatangani_at' => now()->subMinute(),
        ]);

        $returnedDocument = $this->makeDocument('Pedoman Dikembalikan', $type, $unit, $proposer, Document::STATUS_REVISI);
        AuditLog::create([
            'user_id' => $signer->id,
            'user_name' => $signer->name,
            'aksi' => 'tolak_ttd',
            'model_type' => Document::class,
            'model_id' => $returnedDocument->id,
            'deskripsi' => 'Dikembalikan penandatangan: Perbaiki konsideran sebelum disahkan.',
        ]);

        $otherDocument = $this->makeDocument('Dokumen Milik Penandatangan Lain', $type, $unit, $proposer, Document::STATUS_REVISI);
        AuditLog::create([
            'user_id' => $otherSigner->id,
            'user_name' => $otherSigner->name,
            'aksi' => 'tolak_ttd',
            'model_type' => Document::class,
            'model_id' => $otherDocument->id,
            'deskripsi' => 'Dikembalikan penandatangan: Catatan pengguna lain.',
        ]);

        $response = $this->actingAs($signer)->get(route('ttd.index', ['tab' => 'riwayat']));

        $response->assertOk()
            ->assertSee('Riwayat Pengesahan Saya')
            ->assertSee('Kebijakan Sudah Disahkan')
            ->assertSee('Pedoman Dikembalikan')
            ->assertSee('Perbaiki konsideran sebelum disahkan.')
            ->assertSee('Status terkini:')
            ->assertDontSee('Dokumen Milik Penandatangan Lain')
            ->assertDontSee('Dikembalikan penandatangan: Perbaiki konsideran');
    }

    public function test_history_filters_apply_to_history_without_changing_the_active_tab(): void
    {
        $unit = Unit::create(['nama' => 'Direktorat', 'kode' => 'DIR', 'singkatan' => 'DIR', 'urutan' => 1]);
        $type = DocumentType::create([
            'nama' => 'Pedoman', 'kode' => 'PDM', 'singkatan' => 'PDM',
            'format_nomor' => '{urut}/PDM/{tahun}', 'level_verifikasi' => 1, 'urutan' => 1,
        ]);
        $signer = User::factory()->create(['unit_id' => $unit->id]);
        $proposer = User::factory()->create(['unit_id' => $unit->id]);

        foreach (['Sasaran Filter Khusus', 'Dokumen Lain'] as $title) {
            $document = $this->makeDocument($title, $type, $unit, $proposer, Document::STATUS_REVISI);
            AuditLog::create([
                'user_id' => $signer->id,
                'user_name' => $signer->name,
                'aksi' => 'tolak_ttd',
                'model_type' => Document::class,
                'model_id' => $document->id,
                'deskripsi' => 'Dikembalikan penandatangan: Perlu diperbaiki.',
            ]);
        }

        $response = $this->actingAs($signer)->get(route('ttd.index', [
            'tab' => 'riwayat',
            'search' => 'Sasaran Filter',
        ]));

        $response->assertOk()
            ->assertSee('Sasaran Filter Khusus')
            ->assertDontSee('Dokumen Lain')
            ->assertSee('name="tab" value="riwayat"', false);
    }

    private function makeDocument(
        string $title,
        DocumentType $type,
        Unit $unit,
        User $proposer,
        string $status,
    ): Document {
        return Document::create([
            'judul' => $title,
            'document_type_id' => $type->id,
            'unit_id' => $unit->id,
            'pengusul_id' => $proposer->id,
            'status' => $status,
        ]);
    }
}
