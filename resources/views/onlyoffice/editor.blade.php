@extends('layouts.app')

@section('title', 'ONLYOFFICE Editor — ' . $document->judul)

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('dokumen.index') }}" style="color:var(--text-muted)">Dokumen Saya</a>
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('dokumen.show', $document) }}" style="color:var(--text-muted)">{{ Str::limit($document->judul, 20) }}</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">ONLYOFFICE Docs</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; align-items:center; justify-content:space-between">
    <div>
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px">
            <span class="badge badge-purple" style="font-size:0.8rem; padding:4px 10px">ONLYOFFICE Docs Web Application</span>
            <span class="badge badge-indigo">Versi v{{ $version->versi }}</span>
        </div>
        <h1 class="page-title">{{ $document->judul }}</h1>
        <p class="page-subtitle">Editor Resmi Word (.docx) Realtime & Kolaboratif SIMPEL-RS</p>
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
            <strong>Server ONLYOFFICE Docs:</strong> <span style="font-family:monospace; color:var(--brand-700)">{{ config('onlyoffice.url') }}</span>
        </div>
        <div style="color:var(--text-muted); font-size:0.78rem">
            Perubahan dokumen disimpan secara otomatis ke server SIMPEL-RS via Webhook Callback.
        </div>
    </div>

    {{-- OnlyOffice Container --}}
    <div style="height: 820px; width: 100%; position: relative; background: var(--bg-elevated)">
        <div id="onlyoffice-placeholder" style="width:100%; height:100%">
            <div id="onlyoffice-loading-msg" style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; color:var(--text-primary); text-align:center; padding:2rem">
                <div style="font-size:2.5rem; margin-bottom:1rem; animation:pulse-soft 1.5s infinite">📄</div>
                <h3 style="margin-bottom:0.5rem; font-family:var(--font-display)">Menghubungkan ke Server ONLYOFFICE Docs...</h3>
                <p style="color:var(--text-muted); max-width:520px; font-size:0.9rem; line-height:1.6">
                    Memuat antarmuka editor Word resmi. Pastikan service ONLYOFFICE Document Server telah berjalan pada port 8080.
                </p>
                <div style="margin-top:1.5rem; background:var(--bg-active); padding:1rem; border-radius:8px; font-family:monospace; font-size:0.8rem; text-align:left; border:1px solid var(--border-default)">
                    <div style="color:var(--brand-700); margin-bottom:4px"># Perintah Jalankan Server ONLYOFFICE Docs (Docker):</div>
                    <code style="color:#16a34a">docker run -i -t -d -p 8080:80 --restart=always onlyoffice/documentserver</code>
                </div>
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
                <h3 style="margin-bottom:0.5rem; color:#dc2626">Server ONLYOFFICE Docs Tidak Terjangkau</h3>
                <p style="color:var(--text-secondary); max-width:560px; font-size:0.9rem; line-height:1.6">
                    Tidak dapat menghubungkan ke API OnlyOffice pada <code>{{ config('onlyoffice.url') }}</code>. Pastikan container Docker / service ONLYOFFICE Document Server telah diaktifkan.
                </p>
                <div style="margin-top:1.5rem; background:#fef2f2; padding:1rem 1.5rem; border-radius:8px; font-family:monospace; font-size:0.82rem; text-align:left; border:1px solid #fecaca">
                    <div style="color:#b45309; margin-bottom:6px">Jalankan perintah ini di Terminal / Command Prompt:</div>
                    <code style="color:#16a34a">docker run -i -t -d -p 8080:80 onlyoffice/documentserver</code>
                </div>
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
