@extends('layouts.app')

@section('title', 'Text Editor — ' . $document->judul)

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('dokumen.index') }}" style="color:var(--text-muted)">Dokumen Saya</a>
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('dokumen.show', $document) }}" style="color:var(--text-muted)">{{ Str::limit($document->judul, 20) }}</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Text Editor</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; align-items:center; justify-content:space-between">
    <div>
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px">
            <span class="badge badge-purple" style="font-size:0.8rem; padding:4px 10px">Text Editor</span>
            <span class="badge badge-indigo">Versi v{{ $version->versi }}</span>
        </div>
        <h1 class="page-title">{{ $document->judul }}</h1>
        <p class="page-subtitle">Sunting naskah Word langsung dan simpan sebagai versi baru</p>
    </div>
    <div style="display:flex; gap:10px">
        <a href="{{ route('dokumen.show', $document) }}" class="btn btn-secondary">
            &larr; Kembali ke Detail Dokumen
        </a>
    </div>
</div>

<div class="card" style="padding:0; overflow:hidden; border: 1px solid var(--border-brand)">

    {{-- Connection Banner / Status --}}
    <div style="padding: 10px 20px; background: var(--brand-50); border-bottom: 1px solid var(--border-subtle); display:flex; align-items:center; justify-content:space-between; font-size:0.85rem">
        <div style="display:flex; align-items:center; gap:8px; color:var(--text-primary)">
            <span id="status-indicator" style="width:10px; height:10px; border-radius:50%; background:#eab308; display:inline-block"></span>
            <strong>Layanan Text Editor</strong>
        </div>
        <div style="color:var(--text-muted); font-size:0.78rem">
            Perubahan dokumen disimpan otomatis sebagai versi baru di SIMPEL-RS.
        </div>
    </div>

    {{-- OnlyOffice Container --}}
    <div style="height: 820px; width: 100%; position: relative; background: var(--bg-elevated)">
        <div id="onlyoffice-placeholder" style="width:100%; height:100%">
            <div id="onlyoffice-loading-msg" style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; color:var(--text-primary); text-align:center; padding:2rem">
                <div style="font-size:2.5rem; margin-bottom:1rem; animation:pulse-soft 1.5s infinite">📄</div>
                <h3 style="margin-bottom:0.5rem; font-family:var(--font-display)">Menyiapkan Text Editor...</h3>
                <p style="color:var(--text-muted); max-width:520px; font-size:0.9rem; line-height:1.6">
                    Memuat antarmuka penyuntingan naskah. Mohon tunggu beberapa saat.
                </p>
            </div>
        </div>
    </div>

</div>

{{-- OnlyOffice API JS Script --}}
<script src="{{ config('onlyoffice.url') }}/web-apps/apps/api/documents/api.js" onerror="handleOnlyOfficeLoadError()"></script>

<script>
    let docEditor = null;

    function handleOnlyOfficeLoadError() {
        const msg = document.getElementById('onlyoffice-loading-msg');
        const indicator = document.getElementById('status-indicator');
        if (indicator) indicator.style.background = '#ef4444';
        if (msg) {
            msg.innerHTML = `
                <div style="font-size:2.5rem; margin-bottom:1rem">⚠️</div>
                <h3 style="margin-bottom:0.5rem; color:#dc2626">Text Editor Tidak Dapat Dimuat</h3>
                <p style="color:var(--text-secondary); max-width:560px; font-size:0.9rem; line-height:1.6">
                    Layanan penyuntingan sedang tidak tersedia. Silakan coba kembali atau hubungi administrator.
                </p>
            `;
        }
    }

    document.addEventListener("DOMContentLoaded", function () {
        const config = @json($onlyofficeConfig);

        if (typeof DocsAPI !== 'undefined') {
            try {
                const indicator = document.getElementById('status-indicator');
                if (indicator) indicator.style.background = '#22c55e';
                docEditor = new DocsAPI.DocEditor("onlyoffice-placeholder", config);
            } catch (e) {
                console.error("Error inisialisasi ONLYOFFICE Docs:", e);
                handleOnlyOfficeLoadError();
            }
        }
    });
</script>

@endsection
