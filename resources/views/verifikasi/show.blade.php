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
        </div>
    @endif
</div>

@if($verification->document->status === 'ditolak_penandatangan')
<div class="alert alert-danger" style="margin-bottom: var(--space-6); background: rgba(239,68,68,0.1); border: 1px solid var(--border-danger); color: var(--text-danger); padding: var(--space-4); border-radius: var(--radius-md);">
    <strong style="display:flex; align-items:center; gap:8px;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Dokumen dikembalikan oleh Penandatangan
    </strong>
    <div style="margin-top: 8px; font-style: italic;">
        "{{ $verification->document->ditolak_ttd_alasan }}"
    </div>
</div>
@endif

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
                <button type="button" id="tab-btn-kembali" class="btn btn-secondary" style="flex:1; border-color:var(--border-danger); color:var(--text-danger)" onclick="switchMode('kembali')">
                    ⬇ Kembalikan
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

            {{-- Form Kembalikan Bawah --}}
            <form id="form-kembali" method="POST" action="{{ route('verifikasi.teruskan-bawah', $verification) }}" style="display:none">
                @csrf
                <div class="form-group">
                    <label for="catatan_kembali" class="form-label">Catatan Pengembalian <span style="color:#ef4444">*</span></label>
                    <textarea name="catatan" id="catatan_kembali" class="form-control" rows="4" placeholder="Alasan mengapa dokumen dikembalikan ke verifikator tingkat sebelumnya..." required></textarea>
                </div>
                <button type="submit" class="btn btn-lg" style="width:100%; background:var(--bg-elevated); color:var(--text-danger); border:1px solid var(--border-danger);">
                    Kembalikan ke Level Sebelumnya
                </button>
            </form>

            {{-- Form Minta Revisi --}}
            <form id="form-revisi" method="POST" action="{{ route('verifikasi.revisi', $verification) }}" style="display:none">
                @csrf
                <div class="form-group">
                    <label for="catatan_revisi" class="form-label">Catatan Detail Perbaikan Pengusul <span style="color:#ef4444">*</span></label>
                    <textarea name="catatan" id="catatan_revisi" class="form-control" rows="4" placeholder="Jelaskan poin-poin yang perlu diperbaiki oleh pengusul..." required></textarea>
                </div>
                <button type="submit" class="btn btn-warning btn-lg" style="width:100%">
                    Minta Perbaikan ke Pengusul
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
        document.getElementById('form-setuju').style.display = 'none';
        document.getElementById('form-kembali').style.display = 'none';
        document.getElementById('form-revisi').style.display = 'none';
        
        document.getElementById('tab-btn-setuju').className = 'btn btn-secondary';
        document.getElementById('tab-btn-kembali').className = 'btn btn-secondary';
        document.getElementById('tab-btn-revisi').className = 'btn btn-secondary';
        document.getElementById('tab-btn-kembali').style.borderColor = 'var(--border-danger)';
        document.getElementById('tab-btn-kembali').style.color = 'var(--text-danger)';

        if (mode === 'setuju') {
            document.getElementById('form-setuju').style.display = 'block';
            document.getElementById('tab-btn-setuju').className = 'btn btn-success';
        } else if (mode === 'kembali') {
            document.getElementById('form-kembali').style.display = 'block';
            document.getElementById('tab-btn-kembali').className = 'btn';
            document.getElementById('tab-btn-kembali').style.background = 'var(--text-danger)';
            document.getElementById('tab-btn-kembali').style.color = 'white';
        } else {
            document.getElementById('form-revisi').style.display = 'block';
            document.getElementById('tab-btn-revisi').className = 'btn btn-warning';
        }
    }
</script>

@endsection
