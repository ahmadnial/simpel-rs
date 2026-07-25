<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

        $downloadUrl = route('onlyoffice.download', [$document, $version->id]);
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
        $status = $request->input('status');
        $fileUrl = $request->input('url');

        // Status 2 = Editing finished & saved, Status 6 = Force save
        if (($status === 2 || $status === 6) && $fileUrl) {
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
}
