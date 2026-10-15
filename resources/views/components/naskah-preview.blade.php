@props(['document', 'hideToolbar' => false, 'reviewable' => false])

@php
    $version = $document->currentVersion;
    $signature = $document->signature;
    $previewUrl = $version ? route('dokumen.preview-pdf', [$document, $version->id]) : null;
@endphp

<div class="naskah-preview-wrapper"
    @if($previewUrl) data-document-preview data-url="{{ $previewUrl }}" data-reviewable="{{ $reviewable ? '1' : '0' }}" data-hide-toolbar="{{ $hideToolbar ? '1' : '0' }}" @endif>
    @if($previewUrl)
        <div class="document-preview-toolbar">
            <div class="document-preview-tools">
                <span class="document-preview-count" data-preview-page-count>PDF</span>
                @if($reviewable)
                    <label class="document-preview-toggle">
                        <input type="checkbox" data-preview-markers checked>
                        Tandai variabel
                    </label>
                @endif
            </div>
            <div class="document-preview-tools">
                <button type="button" class="btn btn-secondary btn-sm" data-preview-retry hidden>Coba lagi</button>
                <a class="btn btn-secondary btn-sm" data-preview-open target="_blank" rel="noopener" hidden>Buka PDF</a>
            </div>
        </div>
        <p class="document-preview-status" data-preview-status role="status" aria-live="polite">Memuat pratinjau…</p>
        <p class="document-preview-warning" data-preview-warnings role="status" hidden></p>
        @if($reviewable)<div data-preview-pages hidden></div>@endif
        <iframe class="document-preview-frame" data-preview-frame title="Pratinjau dokumen versi {{ $version->versi }}" hidden></iframe>
        @if($reviewable)
            <p class="document-preview-note">Gulir untuk memeriksa dokumen. Variabel ditandai merah; tanggal variabel diberi kotak. QR draft hanya untuk memeriksa posisi.</p>
        @endif
    @else
        <p>Belum ada versi dokumen yang diunggah.</p>
    @endif

    @if($signature)
        <p style="font-size:.85rem; margin-top:12px">
            Disahkan secara elektronik internal oleh {{ $signature->penandatangan->name }}.
            <a href="{{ route('public.verify', $signature->qr_token) }}" target="_blank" rel="noopener noreferrer">Lihat bukti pengesahan</a>
        </p>
    @endif
</div>
