<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class OnlyOfficeController extends Controller
{
    protected DocumentService $documentService;

    public function __construct(DocumentService $documentService)
    {
        $this->documentService = $documentService;
    }

    public function editor(Request $request, Document $document)
    {
        $document->load(['currentVersion', 'documentType', 'unit', 'pengusul']);
        $version = $document->currentVersion;

        abort_unless($version, 404, 'File versi naskah tidak ditemukan.');

        $mode = $request->get('mode', 'edit'); // edit | view
        $user = auth()->user();

        // Unique document key for OnlyOffice caching
        $documentKey = md5($document->id . '-' . $version->id . '-' . $version->updated_at->timestamp);

        // Signed URL: route 'download' & 'callback' TIDAK memakai middleware 'auth' (Document Server
        // memanggilnya server-to-server tanpa cookie sesi), jadi 'download' diamankan lewat signature
        // sementara ini, bukan lewat sesi login.
        $downloadUrl = URL::temporarySignedRoute(
            'onlyoffice.download',
            now()->addHours(4),
            [$document->id, $version->id]
        );
        $callbackUrl = route('onlyoffice.callback', $document);

        $onlyofficeConfig = [
            'documentType' => 'word',
            'document' => [
                'fileType' => 'docx',
                'key'      => $documentKey,
                'title'    => $document->judul . '.docx',
                'url'      => $downloadUrl,
                'permissions' => [
                    'edit'    => $mode === 'edit' && !$document->isLocked(),
                    'download'=> true,
                    'print'   => true,
                ],
            ],
            'editorConfig' => [
                'mode'        => $mode,
                'lang'        => 'id',
                'callbackUrl' => $callbackUrl,
                'user'        => [
                    'id'   => (string) $user->id,
                    'name' => $user->name,
                ],
                'customization' => [
                    'forcesave' => true,
                    'autosave'  => true,
                    'goback'    => [
                        'url' => route('dokumen.show', $document),
                    ],
                ],
            ],
        ];

        if ($jwtSecret = config('onlyoffice.jwt_secret')) {
            $onlyofficeConfig['token'] = $this->signOnlyOfficeConfig($onlyofficeConfig, $jwtSecret);
        }

        return view('onlyoffice.editor', compact('document', 'version', 'onlyofficeConfig'));
    }

    public function download(Document $document, $versionId)
    {
        $version = $document->versions()->findOrFail($versionId);
        $filePath = $version->file_path;

        if (Storage::disk('local')->exists($filePath)) {
            $path = Storage::disk('local')->path($filePath);
        } elseif (file_exists(storage_path('app/' . $filePath))) {
            $path = storage_path('app/' . $filePath);
        } elseif (file_exists(storage_path('app/private/' . $filePath))) {
            $path = storage_path('app/private/' . $filePath);
        } else {
            abort(404, 'File tidak ditemukan.');
        }

        return response()->file($path, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'inline; filename="' . $version->file_name . '"',
        ]);
    }

    public function callback(Request $request, Document $document)
    {
        // Route ini tidak dilindungi sesi login (dipanggil server-to-server oleh OnlyOffice
        // Document Server) — verifikasi JWT bawaan OnlyOffice adalah satu-satunya lapisan
        // otentikasi. Sebelumnya tidak pernah divalidasi meski config('onlyoffice.jwt_secret')
        // sudah disediakan, sehingga siapa pun yang tahu ID dokumen bisa memicu penyimpanan
        // versi baru (bahkan dari URL sembarang / SSRF).
        abort_unless($this->verifyOnlyOfficeJwt($request), 403, 'Invalid OnlyOffice JWT signature.');

        // Konten yang sudah lolos verifikasi/TTE tidak boleh ditimpa lewat editor lagi.
        // Sebelumnya tidak ada pengecekan status sama sekali di sini — hanya UI (permissions.edit
        // di editor()) yang mencegahnya, dan itu bisa dilewati karena bukan enforcement server-side.
        if ($document->isLocked()) {
            logger()->warning('OnlyOffice callback ditolak: dokumen terkunci.', [
                'document_id' => $document->id,
                'status'      => $document->status,
            ]);
            return response()->json(['error' => 1, 'message' => 'Dokumen terkunci, tidak dapat disimpan.']);
        }

        $status = $request->input('status');
        $fileUrl = $request->input('url');

        // Status 2 = Editing finished & saved, Status 6 = Force save
        if (($status === 2 || $status === 6) && $fileUrl && in_array(parse_url($fileUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            try {
                $response = Http::get($fileUrl);
                if ($response->successful()) {
                    $fileName = 'onlyoffice_edited_' . time() . '.docx';
                    $tempPath = storage_path('app/temp/' . $fileName);
                    Storage::disk('local')->makeDirectory('temp');
                    file_put_contents($tempPath, $response->body());

                    // Buat Illuminate UploadedFile dummy untuk simpanVersi
                    $file = new \Illuminate\Http\UploadedFile(
                        $tempPath,
                        $document->judul . '.docx',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        null,
                        true
                    );

                    $this->documentService->simpanVersi(
                        $document,
                        $file,
                        'Disunting via OnlyOffice Docs Web Application'
                    );

                    AuditLog::catat('onlyoffice_save', "Naskah disunting dan disimpan via OnlyOffice Docs", $document);

                    @unlink($tempPath);
                }
            } catch (\Exception $e) {
                logger()->error('OnlyOffice callback error: ' . $e->getMessage());
                return response()->json(['error' => 1, 'message' => $e->getMessage()]);
            }
        }

        return response()->json(['error' => 0]);
    }

    /**
     * Verifikasi JWT yang dikirim OnlyOffice Document Server pada request callback.
     * OnlyOffice mengirim token via header Authorization: Bearer, atau field 'token' di body,
     * tergantung konfigurasi. Jika jwt_secret tidak diisi di config/onlyoffice.php, JWT dianggap
     * tidak diaktifkan pada instance OnlyOffice ini (perilaku default OnlyOffice sendiri).
     */
    private function verifyOnlyOfficeJwt(Request $request): bool
    {
        $secret = config('onlyoffice.jwt_secret');
        if (!$secret) {
            return true;
        }

        $token = $request->bearerToken() ?? $request->input('token');
        if (!$token || substr_count($token, '.') !== 2) {
            return false;
        }

        [$header, $body, $signature] = explode('.', $token);
        $expected = $this->base64UrlEncode(hash_hmac('sha256', "{$header}.{$body}", $secret, true));

        return hash_equals($expected, $signature);
    }

    /**
     * Tanda-tangani payload konfigurasi editor dengan HS256 supaya Document Server mempercayai
     * config yang dikirim front-end (wajib bila JWT diaktifkan di sisi OnlyOffice).
     */
    private function signOnlyOfficeConfig(array $payload, string $secret): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $this->base64UrlEncode(json_encode($payload));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', "{$header}.{$body}", $secret, true));

        return "{$header}.{$body}.{$signature}";
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
