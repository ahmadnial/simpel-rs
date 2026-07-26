@props(['document'])

@php
    $currentVersion = $document->currentVersion;
    $previewUrl = $currentVersion ? route('dokumen.preview-pdf', [$document, $currentVersion->id]) : null;
    $signature = $document->signature;
    $uniqueId = 'doc_' . $document->id . '_' . uniqid();
@endphp

<div class="naskah-preview-wrapper" style="width:100%; max-width:920px; margin:0 auto;">

    @if($previewUrl)
        <div style="background:#ffffff; border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,0.08); padding:0; border:1px solid var(--border-color); position:relative; overflow:hidden;">
            
            {{-- Loading Indicator --}}
            <div id="loading-{{ $uniqueId }}" style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); text-align:center; padding:20px 0; color:var(--text-muted, #64748b); z-index:10; background:rgba(255,255,255,0.9); width:100%; height:100%; display:flex; flex-direction:column; justify-content:center; align-items:center;">
                <div style="width:2.2rem; height:2.2rem; border:3px solid #cbd5e1; border-top-color:#3b82f6; border-radius:50%; animation:spin 0.8s linear infinite; margin:0 auto 10px;"></div>
                <div style="font-size:0.85rem; font-weight:600;">Mengonversi Format Dokumen Asli...</div>
                <div style="font-size:0.75rem; margin-top:5px; color:#94a3b8;">Menggunakan LibreOffice 100% Presisi</div>
            </div>

            {{-- PDF Iframe --}}
            <iframe id="pdf-frame-{{ $uniqueId }}" src="{{ $previewUrl }}" style="width:100%; height:800px; border:none; display:block;" onload="document.getElementById('loading-{{ $uniqueId }}').style.display='none';"></iframe>

        </div>
    @else
        <div class="card" style="text-align:center; padding:3rem 1.5rem; color:var(--text-muted)">
            <p>Belum ada versi dokumen yang diunggah.</p>
        </div>
    @endif

    @if($signature)
        <div style="margin-top:20px; padding:14px 18px; background:#f0fdf4; border:1px solid #86efac; border-radius:10px; display:flex; align-items:center; justify-content:space-between; font-size:0.88rem">
            <div style="display:flex; align-items:center; gap:12px; color:#166534">
                <span style="font-size:1.4rem">🔏</span>
                <div>
                    <strong style="color:#14532d">Dokumen Terverifikasi TTE Sah</strong> &bull; {{ $signature->penandatangan->name }} ({{ $signature->ditandatangani_at->translatedFormat('d F Y H:i') }} WIB)
                </div>
            </div>
            <a href="{{ route('public.verify', $signature->qr_token) }}" target="_blank" style="color:#15803d; font-weight:700; text-decoration:underline; font-size:0.8rem">Cek Sertifikat QR</a>
        </div>
    @endif

</div>

@if($previewUrl)
<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>
@endif
