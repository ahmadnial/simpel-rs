@extends('layouts.app')

@section('title', 'Review Verifikasi')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('verifikasi.index') }}" style="color:var(--text-muted)">Antrian Verifikasi</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Review Naskah</span>
@endsection

@section('content')

@php
    $isCurrentTicket = $verification->isActionable((int) auth()->id());
    $isReopenedTicket = in_array($verification->activation_reason, [
        \App\Models\DocumentVerification::REASON_RETURNED_BY_VERIFIER,
        \App\Models\DocumentVerification::REASON_RETURNED_BY_SIGNER,
    ], true);
    [$decisionLabel, $decisionColor] = match ($verification->status) {
        'disetujui' => ['Sudah Disetujui', 'badge-green'],
        'revisi' => ['Revisi Diminta', 'badge-orange'],
        'ditolak' => ['Ditolak', 'badge-red'],
        'dikembalikan' => ['Dikembalikan ke Level Sebelumnya', 'badge-orange'],
        'batal' => [$verification->closureLabel(), 'badge-gray'],
        default => ['Belum Diputuskan', 'badge-yellow'],
    };
    $priorApprovals = $verification->document->verifications->filter(fn ($ticket) =>
        $ticket->verifikator_id === $verification->verifikator_id
        && $ticket->document_version_id === $verification->document_version_id
        && $ticket->level < $verification->level
        && $ticket->isApproved()
    );
@endphp

<div class="page-header" style="display:flex; align-items:flex-start; justify-content:space-between">
    <div>
        <span class="badge {{ $decisionColor }}">{{ $decisionLabel }}</span>
        <span class="badge badge-indigo">Level {{ $verification->level }} · {{ $verification->workflowStep?->nama_tahap }}</span>
        <span class="badge {{ $isCurrentTicket ? 'badge-yellow' : 'badge-gray' }}" style="margin-bottom:8px">
            {{ $isCurrentTicket ? ($isReopenedTicket ? 'Pemeriksaan Ulang · Putaran '.$verification->verification_round : 'Menunggu Keputusan Anda') : 'Tiket Riwayat · Tidak Aktif' }}
        </span>
        <h1 class="page-title">{{ $verification->document->judul }}</h1>
        <p class="page-subtitle">
            Diajukan oleh <strong>{{ $verification->document->pengusul->name }}</strong> ({{ $verification->document->unit->nama }}) &bull; Versi Aktif: v{{ $verification->document->currentVersion->versi }}
        </p>
    </div>
    @if($verification->document->currentVersion)
        <div style="display:flex; gap:10px">
            <a href="{{ route('onlyoffice.editor', ['document' => $verification->document->id, 'mode' => 'view']) }}" class="btn btn-primary btn-lg" style="background: linear-gradient(135deg, #a855f7, #7c3aed)" title="Lihat naskah di Text Editor">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Text Editor
            </a>
        </div>
    @endif
</div>

@if(!$isCurrentTicket && $verification->isMenunggu() && $priorApprovals->isNotEmpty())
<div class="alert alert-info" style="margin-bottom: var(--space-6)">
    <strong>Persetujuan level sebelumnya sudah tercatat.</strong>
    Verifikator pada tiket ini sudah menyetujui Level {{ $priorApprovals->pluck('level')->unique()->sort()->implode(', ') }}.
    Pemeriksaan Level {{ $verification->level }} harus dilakukan oleh verifikator berbeda. Penugasan ini tidak dapat diproses.
</div>
@endif

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

@if($isCurrentTicket && $verification->activation_reason === \App\Models\DocumentVerification::REASON_RETURNED_BY_VERIFIER)
<div class="alert alert-info" style="margin-bottom: var(--space-6)">
    <strong>Pemeriksaan ulang dari level berikutnya.</strong>
    Keputusan sebelumnya tetap tersimpan pada riwayat Putaran {{ max(1, $verification->verification_round - 1) }}.
    @if($verification->reopenedFrom?->catatan)
        <div style="margin-top:8px; font-style:italic">“{{ $verification->reopenedFrom->catatan }}”</div>
    @endif
</div>
@endif

<div class="workflow-review-grid" style="display:grid; grid-template-columns: 3fr 2fr; gap: var(--space-6)">

    {{-- Left: Keputusan & Form --}}
    <div style="display:flex; flex-direction:column; gap: var(--space-6)">

        {{-- Form Setujui / Minta Revisi --}}
        <div class="card">
            <div class="card-header">
                <span class="card-title">{{ $isCurrentTicket ? 'Form Keputusan Verifikasi' : 'Hasil Keputusan Verifikasi' }} · Level {{ $verification->level }}</span>
            </div>

            @if($isCurrentTicket)
            <div class="verification-action-tabs" style="display:flex; gap: var(--space-3); margin-bottom: var(--space-4)">
                <button type="button" id="tab-btn-setuju" class="btn btn-success" style="flex:1" onclick="switchMode('setuju')">
                    ✓ Setujui Dokumen
                </button>
                @if($verification->level > $verification->document->verifications->where('document_version_id', $verification->document_version_id)->min('level'))
                    <button type="button" id="tab-btn-kembali" class="btn btn-secondary verification-return-tab" style="flex:1" onclick="switchMode('kembali')">
                        ⬇ Ke Level Sebelumnya
                    </button>
                @endif
                <button type="button" id="tab-btn-revisi" class="btn btn-secondary" style="flex:1" onclick="switchMode('revisi')">
                    ✍ Minta Revisi
                </button>
            </div>

            <p style="font-size:.8rem; color:var(--text-muted); margin-bottom:var(--space-4)">“Minta revisi” mengembalikan naskah ke pengusul dan mewajibkan versi baru. “Ke level sebelumnya” hanya membuka kembali keputusan level di bawah tanpa mengubah berkas.</p>

            {{-- Form Setujui --}}
            <form id="form-setuju" method="POST" action="{{ route('verifikasi.setujui', $verification) }}">
                @csrf
                <div class="form-group">
                    <label for="catatan_setuju" class="form-label">Catatan Persetujuan (Opsional)</label>
                    <textarea name="catatan" id="catatan_setuju" class="form-control" rows="3" placeholder="mis: Dokumen sudah sesuai dengan format baku dan kebijakan RS."></textarea>
                </div>
                <button type="submit" class="btn btn-success btn-lg" style="width:100%">
                    Setujui & Lanjutkan Alur
                </button>
            </form>

            {{-- Form Kembalikan Bawah --}}
            <form id="form-kembali" method="POST" action="{{ route('verifikasi.teruskan-bawah', $verification) }}" style="display:none" data-confirm="Dokumen akan dikembalikan ke level verifikasi sebelumnya. Lanjutkan tindakan ini?">
                @csrf
                <div class="form-group">
                    <label for="catatan_kembali" class="form-label">Catatan Pengembalian <span style="color:#ef4444">*</span></label>
                    <textarea name="catatan" id="catatan_kembali" class="form-control" rows="4" placeholder="Alasan mengapa dokumen dikembalikan ke verifikator tingkat sebelumnya..." required></textarea>
                </div>
                <button type="submit" class="btn btn-danger btn-lg" style="width:100%;">
                    Kembalikan ke Level Sebelumnya
                </button>
            </form>

            {{-- Form Minta Revisi --}}
            <form id="form-revisi" method="POST" action="{{ route('verifikasi.revisi', $verification) }}" style="display:none" data-confirm="Dokumen akan dikembalikan kepada pengusul untuk diperbaiki. Lanjutkan tindakan ini?">
                @csrf
                <div class="form-group">
                    <label for="catatan_revisi" class="form-label">Catatan Detail Perbaikan Pengusul <span style="color:#ef4444">*</span></label>
                    <textarea name="catatan" id="catatan_revisi" class="form-control" rows="4" placeholder="Jelaskan poin-poin yang perlu diperbaiki oleh pengusul..." required></textarea>
                </div>
                <button type="submit" class="btn btn-warning btn-lg" style="width:100%">
                    Minta Perbaikan ke Pengusul
                </button>
            </form>
            @else
                <p><strong>{{ $decisionLabel }}</strong>
                    @if($verification->direspon_at ?? $verification->direset_at)
                        · {{ ($verification->direspon_at ?? $verification->direset_at)->format('d/m/Y H:i') }}
                    @endif
                </p>
                @if($verification->resolvedDecisionMaker())
                    <p>Diputuskan oleh: <strong>{{ $verification->resolvedDecisionMaker()->name }}</strong></p>
                @endif
                @if($verification->catatan)
                    <p>{{ $verification->catatan }}</p>
                @endif
                <div class="alert alert-info">
                    @if($verification->direset_alasan)
                        {{ $verification->direset_alasan }}
                    @elseif($verification->isDibatalkan())
                        Penugasan ini telah ditutup. Alasan penutupan tidak tercatat pada data lama.
                    @else
                        Tiket ini tidak lagi aktif. Keputusan hanya dapat diberikan pada penugasan pemeriksaan yang sedang aktif.
                    @endif
                </div>
            @endif
        </div>

        {{-- Embedded Pratinjau Dokumen Naskah Dinas --}}
        <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center">
                <span class="card-title">Pratinjau Lembar Dokumen</span>
                <span class="badge badge-indigo">Versi v{{ $verification->document->currentVersion->versi ?? 1 }}</span>
            </div>
            <div class="docx-paper-wrapper">
                <x-naskah-preview :document="$verification->document" />
            </div>
        </div>

        {{-- Informasi Naskah --}}
        <div class="card">
            <div class="card-header">
                <span class="card-title">Ringkasan Dokumen</span>
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
            {{-- Dikelompokkan per level, sama seperti di halaman detail dokumen — kalau satu
                 tahap punya pool verifikator >1 orang yang masih sama-sama menunggu, dibungkus
                 jadi 1 baris ringkas, bukan 1 baris identik per orang. Tiket yang otomatis
                 dibatalkan (kalah cepat) disembunyikan; begitu ada yang benar-benar bertindak,
                 namanya tampil sendiri. --}}
            @foreach($verification->document->verifications->sortBy(fn ($v) => sprintf('%010d-%010d-%010d-%010d', $v->version?->versi ?? 0, $v->verification_round, $v->level, $v->id))->groupBy(fn ($v) => $v->document_version_id.'-'.$v->verification_round.'-'.$v->level) as $cycleGroup)
                @php
                    $levelGroup = $cycleGroup;
                    $level = $levelGroup->first()->level;
                    $round = $levelGroup->first()->verification_round;
                    $versionNumber = $levelGroup->first()->version?->versi ?? '?';
                    $decided = $levelGroup->reject(fn ($v) => $v->isMenunggu() || $v->isDibatalkan());
                    $pending = $levelGroup->filter(fn ($v) => $v->isMenunggu());
                @endphp

                @foreach($decided as $v)
                <div class="timeline-item">
                    <div class="timeline-dot" style="background:var(--bg-elevated); color:var(--text-muted)">{{ $level }}</div>
                    <div class="timeline-content">
                        <div class="timeline-title">{{ $v->resolvedDecisionMaker()?->name ?? $v->verifikator->name }}</div>
                        <div class="timeline-meta">Versi {{ $versionNumber }} · Putaran {{ $round }} · Status: <strong>{{ $v->isDikembalikan() ? 'Ke Level Sebelumnya' : ucfirst($v->status) }}</strong></div>
                        @if($v->catatan)
                            <div class="timeline-note">"{{ $v->catatan }}"</div>
                        @endif
                        @include('verifikasi.closed-peer-summary', ['tickets' => $verification->document->verifications, 'decision' => $v])
                    </div>
                </div>
                @endforeach

                @if($pending->count() === 1)
                    @php $v = $pending->first(); @endphp
                    <div class="timeline-item">
                        <div class="timeline-dot" style="background:var(--bg-elevated); color:var(--text-muted)">{{ $level }}</div>
                        <div class="timeline-content">
                            <div class="timeline-title">{{ $v->verifikator->name }}</div>
                            <div class="timeline-meta">Versi {{ $versionNumber }} · Putaran {{ $round }} · Status: <strong>Menunggu</strong></div>
                        </div>
                    </div>
                @elseif($pending->count() > 1)
                    @php
                        $pendingSubs = $pending->map(fn ($v) => $v->verifikator->jabatan ?: $v->verifikator->unit?->nama)->filter()->unique();
                        $pendingCommonSub = $pendingSubs->count() === 1 ? $pendingSubs->first() : null;
                    @endphp
                    <div class="timeline-item">
                        <div class="timeline-dot" style="background:var(--bg-elevated); color:var(--text-muted)">{{ $level }}</div>
                        <div class="timeline-content">
                            <div class="timeline-title">
                                Menunggu Verifikasi
                                @if($pendingCommonSub)
                                    <span style="font-weight:500; color:var(--text-muted)">&middot; {{ $pendingCommonSub }}</span>
                                @endif
                            </div>
                            <div class="timeline-meta">Versi {{ $versionNumber }} · Putaran {{ $round }} · Menunggu salah satu dari {{ $pending->count() }} verifikator</div>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>

</div>

<script>
    // Browser dapat memulihkan form lama lewat back/forward cache setelah keputusan.
    window.addEventListener('pageshow', event => {
        if (event.persisted) window.location.reload();
    });
    function switchMode(mode) {
        document.getElementById('form-setuju').style.display = 'none';
        const formKembali = document.getElementById('form-kembali');
        if (formKembali) formKembali.style.display = 'none';
        document.getElementById('form-revisi').style.display = 'none';
        
        document.getElementById('tab-btn-setuju').className = 'btn btn-secondary';
        const btnKembali = document.getElementById('tab-btn-kembali');
        if (btnKembali) btnKembali.className = 'btn btn-secondary';
        document.getElementById('tab-btn-revisi').className = 'btn btn-secondary';

        if (mode === 'setuju') {
            document.getElementById('form-setuju').style.display = 'block';
            document.getElementById('tab-btn-setuju').className = 'btn btn-success';
        } else if (mode === 'kembali') {
            formKembali.style.display = 'block';
            btnKembali.className = 'btn btn-danger';
        } else {
            document.getElementById('form-revisi').style.display = 'block';
            document.getElementById('tab-btn-revisi').className = 'btn btn-warning';
        }
    }

    document.querySelectorAll('form[action*="/verifikasi/"]').forEach(form => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('button[type="submit"]').forEach(button => {
                button.disabled = true;
                button.textContent = 'Memproses keputusan…';
            });
        });
    });
</script>

@endsection
