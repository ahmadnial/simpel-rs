@extends('layouts.app')

@section('title', 'Review Verifikasi')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('verifikasi.index') }}" style="color:var(--text-muted)">Antrian Verifikasi</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Review Naskah</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; align-items:flex-start; justify-content:space-between">
    <div>
        <span class="badge badge-yellow" style="margin-bottom:8px">Menunggu Keputusan Anda</span>
        <h1 class="page-title">{{ $verification->document->judul }}</h1>
        <p class="page-subtitle">
            Diajukan oleh <strong>{{ $verification->document->pengusul->name }}</strong> ({{ $verification->document->unit->nama }}) &bull; Versi Aktif: v{{ $verification->document->currentVersion->versi }}
        </p>
    </div>
    @if($verification->document->currentVersion)
        <div style="display:flex; gap:10px">
            <a href="{{ route('onlyoffice.editor', ['document' => $verification->document->id, 'mode' => 'edit']) }}" class="btn btn-primary btn-lg" style="background: linear-gradient(135deg, #a855f7, #7c3aed)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Buka di OnlyOffice Docs
            </a>
            <a href="{{ route('dokumen.download', [$verification->document, $verification->document->currentVersion->id]) }}" class="btn btn-secondary btn-lg">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Unduh (.docx)
            </a>
        </div>
    @endif
</div>

<div style="display:grid; grid-template-columns: 3fr 2fr; gap: var(--space-6)">

    {{-- Left: Keputusan & Form --}}
    <div style="display:flex; flex-direction:column; gap: var(--space-6)">

        {{-- Form Setujui / Minta Revisi --}}
        <div class="card">
            <div class="card-header">
                <span class="card-title">Form Keputusan Verifikasi</span>
            </div>

            <div style="display:flex; gap: var(--space-3); margin-bottom: var(--space-4)">
                <button type="button" id="tab-btn-setuju" class="btn btn-success" style="flex:1" onclick="switchMode('setuju')">
                    ✓ Setujui Dokumen
                </button>
                <button type="button" id="tab-btn-revisi" class="btn btn-secondary" style="flex:1" onclick="switchMode('revisi')">
                    ✍ Minta Revisi
                </button>
            </div>

            {{-- Form Setujui --}}
            <form id="form-setuju" method="POST" action="{{ route('verifikasi.setujui', $verification) }}">
                @csrf
                <div class="form-group">
                    <label for="catatan_setuju" class="form-label">Catatan Persetujuan (Opsional)</label>
                    <textarea name="catatan" id="catatan_setuju" class="form-control" rows="3" placeholder="mis: Dokumen sudah sesuai dengan format baku dan kebijakan RS."></textarea>
                </div>
                <button type="submit" class="btn btn-success btn-lg" style="width:100%">
                    Setujui & Lanjutkan Workflow
                </button>
            </form>

            {{-- Form Minta Revisi --}}
            <form id="form-revisi" method="POST" action="{{ route('verifikasi.revisi', $verification) }}" style="display:none">
                @csrf
                <div class="form-group">
                    <label for="catatan_revisi" class="form-label">Catatan Detail Revisi <span style="color:#ef4444">*</span></label>
                    <textarea name="catatan" id="catatan_revisi" class="form-control" rows="4" placeholder="Jelaskan poin-poin yang perlu diperbaiki oleh pengusul..." required></textarea>
                </div>
                <button type="submit" class="btn btn-warning btn-lg" style="width:100%">
                    Kirim Catatan Revisi ke Pengusul
                </button>
            </form>
        </div>

        {{-- Embedded Pratinjau Dokumen Naskah Dinas --}}
        <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center">
                <span class="card-title">Pratinjau Lembar Naskah Dinas</span>
                <span class="badge badge-indigo">Versi v{{ $verification->document->currentVersion->versi ?? 1 }}</span>
            </div>
            <div class="docx-paper-wrapper">
                <x-naskah-preview :document="$verification->document" />
            </div>
        </div>

        {{-- Informasi Naskah --}}
        <div class="card">
            <div class="card-header">
                <span class="card-title">Ringkasan Naskah Dinas</span>
            </div>
            <div style="font-size:0.875rem; display:flex; flex-direction:column; gap:12px">
                <div>
                    <span style="color:var(--text-muted)">Jenis Naskah:</span>
                    <strong>{{ $verification->document->documentType->nama }}</strong>
                </div>
                <div>
                    <span style="color:var(--text-muted)">Perihal:</span>
                    <div>{{ $verification->document->perihal ?? '-' }}</div>
                </div>
                <div>
                    <span style="color:var(--text-muted)">Keterangan Tambahan:</span>
                    <div>{{ $verification->document->keterangan ?? '-' }}</div>
                </div>
            </div>
        </div>

    </div>

    {{-- Right: Timeline & Catatan Lain --}}
    <div class="card">
        <div class="card-header">
            <span class="card-title">Riwayat Verifikasi Dokumen</span>
        </div>
        <div class="timeline">
            @foreach($verification->document->verifications as $v)
            <div class="timeline-item">
                <div class="timeline-dot" style="background:var(--bg-elevated); color:var(--text-muted)">{{ $v->level }}</div>
                <div class="timeline-content">
                    <div class="timeline-title">{{ $v->verifikator->name }}</div>
                    <div class="timeline-meta">Status: <strong>{{ ucfirst($v->status) }}</strong></div>
                    @if($v->catatan)
                        <div class="timeline-note">"{{ $v->catatan }}"</div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>

</div>

<script>
    function switchMode(mode) {
        if (mode === 'setuju') {
            document.getElementById('form-setuju').style.display = 'block';
            document.getElementById('form-revisi').style.display = 'none';
            document.getElementById('tab-btn-setuju').className = 'btn btn-success';
            document.getElementById('tab-btn-revisi').className = 'btn btn-secondary';
        } else {
            document.getElementById('form-setuju').style.display = 'none';
            document.getElementById('form-revisi').style.display = 'block';
            document.getElementById('tab-btn-setuju').className = 'btn btn-secondary';
            document.getElementById('tab-btn-revisi').className = 'btn btn-warning';
        }
    }

    // Auto-render Docx Paper Preview
    document.addEventListener("DOMContentLoaded", function () {
        const previewUrl = "{{ route('dokumen.preview', [$verification->document, $verification->document->currentVersion?->id]) }}";
        const container  = document.getElementById("docx-preview-container");

        fetch(previewUrl)
            .then(res => {
                if (!res.ok) throw new Error("Gagal mengambil berkas.");
                return res.blob();
            })
            .then(blob => {
                if (typeof docx !== 'undefined') {
                    container.innerHTML = "";
                    docx.renderAsync(blob, container, null, {
                        inWrapper: false,
                        ignoreWidth: true,
                        breakPages: true
                    });
                }
            })
            .catch(err => {
                container.innerHTML = '<div style="color:#ef4444; padding:2rem; text-align:center">Gagal memuat pratinjau dokumen. Silakan unduh berkas secara manual.</div>';
            });
    });
</script>

@endsection
