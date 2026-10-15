<?php

namespace Tests\Support;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\DocumentPdfService;
use Illuminate\Support\Facades\Storage;

/** Signing tests exercise byte integrity; real Office conversion has a separate integration test. */
class FakeDocumentPdfService extends DocumentPdfService
{
    public function render(string $docxPath, Document $document, DocumentVersion $version): string
    {
        $path = 'fixtures/office-'.hash_file('sha256', $docxPath).'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\n% Synthetic signing fixture\n".hash_file('sha256', $docxPath)."\n%%EOF");

        return Storage::disk('local')->path($path);
    }
}
