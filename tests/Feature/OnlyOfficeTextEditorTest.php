<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use ZipArchive;

class OnlyOfficeTextEditorTest extends TestCase
{
    use RefreshDatabase;

    private string $jwtSecret = 'onlyoffice-test-secret-with-at-least-32-bytes';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'onlyoffice.url' => 'https://office.example.test',
            'onlyoffice.jwt_secret' => $this->jwtSecret,
            'onlyoffice.allowed_hosts' => ['office.example.test'],
            'onlyoffice.download_url_ttl_minutes' => 60,
            'onlyoffice.callback_url_ttl_minutes' => 1440,
            'onlyoffice.callback_timeout_seconds' => 30,
            'onlyoffice.max_document_kilobytes' => 10240,
        ]);
    }

    public function test_proposer_can_open_direct_editor_with_text_editor_label(): void
    {
        [$user, $document] = $this->fixture();

        $response = $this->actingAs($user)->get(route('onlyoffice.editor', $document));

        $response->assertOk()
            ->assertSee('Text Editor')
            ->assertDontSee('Edit Web (OnlyOffice)');
    }

    public function test_signed_jwt_callback_saves_valid_docx_as_new_version(): void
    {
        [, $document] = $this->fixture();
        $editedBytes = $this->validDocxBytes('Isi hasil penyuntingan');
        Http::fake([
            'https://office.example.test/*' => Http::response($editedBytes, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]),
        ]);
        $callbackUrl = URL::temporarySignedRoute('onlyoffice.callback', now()->addHour(), [$document->id]);

        $this->postJson($callbackUrl, [
            'key' => 'document-editor-key',
            'status' => 2,
            'filetype' => 'docx',
            'url' => 'https://office.example.test/cache/edited.docx',
            'token' => $this->jwt(['status' => 2]),
        ])->assertOk()->assertExactJson(['error' => 0]);

        $this->assertSame(2, $document->versions()->count());
        $latest = $document->currentVersion()->firstOrFail();
        $this->assertSame(2, $latest->versi);
        $this->assertSame('Disunting melalui Text Editor', $latest->catatan);
        $this->assertTrue(Storage::disk('local')->exists($latest->file_path));
        $this->assertSame([], Storage::disk('local')->allFiles('temp'));
    }

    public function test_callback_without_signed_url_is_rejected(): void
    {
        [, $document] = $this->fixture();

        $this->postJson(route('onlyoffice.callback', $document), [
            'status' => 2,
            'url' => 'https://office.example.test/cache/edited.docx',
            'token' => $this->jwt(['status' => 2]),
        ])->assertForbidden();

        $this->assertSame(1, $document->versions()->count());
    }

    public function test_force_saves_using_same_url_keep_distinct_content_without_duplicating_same_bytes(): void
    {
        [, $document] = $this->fixture();
        $firstBytes = $this->validDocxBytes('Perubahan pertama');
        $secondBytes = $this->validDocxBytes('Perubahan kedua');
        Http::fake([
            'https://office.example.test/*' => Http::sequence()
                ->push($firstBytes)
                ->push($secondBytes)
                ->push($secondBytes),
        ]);
        $callbackUrl = URL::temporarySignedRoute('onlyoffice.callback', now()->addHour(), [$document->id]);
        $payload = [
            'key' => 'same-editor-key',
            'status' => 6,
            'forcesavetype' => 1,
            'url' => 'https://office.example.test/cache/current.docx',
            'token' => $this->jwt(['status' => 6]),
        ];

        $this->postJson($callbackUrl, $payload)->assertOk()->assertJsonPath('error', 0);
        $this->postJson($callbackUrl, $payload)->assertOk()->assertJsonPath('error', 0);
        $this->postJson($callbackUrl, $payload)->assertOk()->assertJsonPath('error', 0);

        $this->assertSame(3, $document->versions()->count());
        $this->assertSame(3, $document->currentVersion()->firstOrFail()->versi);
    }

    public function test_callback_cannot_save_after_document_is_locked(): void
    {
        [, $document] = $this->fixture();
        $document->update(['status' => Document::STATUS_DIAJUKAN]);
        $callbackUrl = URL::temporarySignedRoute('onlyoffice.callback', now()->addHour(), [$document->id]);

        $this->postJson($callbackUrl, [
            'status' => 2,
            'url' => 'https://office.example.test/cache/edited.docx',
            'token' => $this->jwt(['status' => 2]),
        ])->assertOk()->assertExactJson([
            'error' => 1,
            'message' => 'Dokumen terkunci, tidak dapat disimpan.',
        ]);

        $this->assertSame(1, $document->versions()->count());
    }

    /** @return array{User, Document} */
    private function fixture(): array
    {
        $unit = Unit::create(['nama' => 'Sekretariat', 'kode' => 'SEK', 'urutan' => 1]);
        $user = User::create([
            'name' => 'Pengusul', 'email' => 'pengusul@example.test', 'password' => bcrypt('password'),
            'unit_id' => $unit->id, 'is_active' => true,
        ]);
        $type = DocumentType::create([
            'nama' => 'Surat', 'kode' => 'ST', 'singkatan' => 'ST',
            'format_nomor' => '{urut}/ST/{tahun}', 'is_active' => true, 'urutan' => 1,
        ]);
        $document = Document::create([
            'judul' => 'Surat Pengujian Text Editor', 'document_type_id' => $type->id,
            'unit_id' => $unit->id, 'pengusul_id' => $user->id,
            'status' => Document::STATUS_DRAFT, 'visibility_scope' => 'terbatas',
        ]);
        $path = "documents/{$document->id}/source.docx";
        Storage::disk('local')->put($path, $this->validDocxBytes('Isi awal'));
        DocumentVersion::create([
            'document_id' => $document->id, 'versi' => 1, 'file_path' => $path,
            'file_name' => 'source.docx', 'file_size' => Storage::disk('local')->size($path),
            'uploaded_by' => $user->id, 'is_current' => true,
        ]);

        return [$user, $document->fresh('currentVersion')];
    }

    private function validDocxBytes(string $text): string
    {
        $path = tempnam(sys_get_temp_dir(), 'onlyoffice_docx_');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p></w:body></w:document>'
        );
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function jwt(array $payload): string
    {
        $header = $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64Url(hash_hmac('sha256', "{$header}.{$body}", $this->jwtSecret, true));

        return "{$header}.{$body}.{$signature}";
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
