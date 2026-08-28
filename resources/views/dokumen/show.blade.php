@extends('layouts.app')

@section('title', $document->judul)

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('dokumen.index') }}" style="color:var(--text-muted)">Dokumen Saya</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">{{ Str::limit($document->judul, 25) }}</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; align-items:flex-start; justify-content:space-between; gap: var(--space-4)">
    <div>
        <div style="display:flex; align-items:center; gap: var(--space-3); margin-bottom: var(--space-2)">
            <span class="badge badge-indigo" style="font-size:0.8rem">{{ $document->documentType->nama }}</span>
            @php $colorMap = ['gray'=>'badge-gray','blue'=>'badge-blue','yellow'=>'badge-yellow','orange'=>'badge-orange','purple'=>'badge-purple','green'=>'badge-green','teal'=>'badge-teal','indigo'=>'badge-indigo','red'=>'badge-red']; @endphp
            <span class="badge {{ $colorMap[$document->status_color] ?? 'badge-gray' }}">{{ $document->status_label }}</span>
            @if($document->is_rahasia)
                <span class="badge badge-red">RAHASIA</span>
            @endif
        </div>
        <h1 class="page-title">{{ $document->judul }}</h1>
        <p class="page-subtitle">
            Pengusul: <strong>{{ $document->pengusul->name }}</strong> ({{ $document->unit->nama }}) &bull; Dibuat {{ $document->created_at->format('d/m/Y H:i') }}
        </p>

        @if($document->status === \App\Models\Document::STATUS_DITARIK)
            <div style="padding:12px 16px; background:#fef3c7; border:1px solid #fbbf24; border-radius:8px; margin-top:12px;">
                <strong style="color:#92400e;">⚠️ Dokumen ini telah ditarik dari publikasi</strong>
                <p style="margin:6px 0 0; font-size:0.85rem; color:#78350f;">
                    <strong>Alasan:</strong> {{ $document->alasan_penarikan }}<br>
                    <strong>Ditarik pada:</strong> {{ $document->ditarik_at?->format('d/m/Y H:i') }}
                    @if($document->penggantiDocument)
                        <br><strong>Digantikan oleh:</strong>
                        <a href="{{ route('dokumen.show', $document->penggantiDocument) }}" style="color:#b45309; font-weight:600;">
                            {{ $document->penggantiDocument->nomor_surat }}
                        </a>
                    @endif
                </p>
            </div>
        @endif
    </div>

    <div style="display:flex; gap: var(--space-2)">
        @if($document->currentVersion && in_array($document->status, [\App\Models\Document::STATUS_DITANDATANGANI, \App\Models\Document::STATUS_DIPUBLIKASIKAN, \App\Models\Document::STATUS_DIARSIPKAN]))
            <a href="{{ route('dokumen.download-pdf', $document) }}" class="btn btn-primary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M12 18v-6"/><path d="m9 15 3 3 3-3"/></svg>
                Unduh PDF Resmi (Pengesahan Internal)
            </a>
        @endif

        @if($document->isDraft() || $document->isRevisi() || $document->status === 'dikembalikan')
            <a href="{{ route('onlyoffice.editor', $document) }}" class="btn btn-warning" title="Buka Editor OnlyOffice">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Edit Web (OnlyOffice)
            </a>
            <button class="btn btn-secondary" onclick="document.getElementById('modal-upload-versi').style.display='flex'">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Unggah Berkas Perbaikan
            </button>
            <button class="btn btn-primary" onclick="document.getElementById('modal-ajukan').style.display='flex'">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Ajukan ke Verifikator
            </button>
        @endif
    </div>
</div>

@if($document->nomor_surat)
<div class="alert alert-success fade-in" style="margin-bottom: var(--space-6)">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
    <div>
        <strong>Dokumen Berhasil Ditandatangani & Bernomor Resmi:</strong>
        <span style="font-family:monospace; font-size:1.05rem; font-weight:700; margin-left:8px">{{ $document->nomor_surat }}</span>
    </div>
</div>
@else
<div class="alert alert-warning fade-in" style="margin-bottom: var(--space-6); background: #fffbeb; border: 1px solid #fde68a; color: #b45309; display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:8px;">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div>
        <strong>Status Dokumen: Draft / Dalam Proses</strong>
        <div style="font-size: 0.85rem; margin-top: 2px;">Nomor naskah dinas resmi diterbitkan otomatis setelah dokumen disahkan secara elektronik.</div>
    </div>
</div>
@endif

<div style="display:grid; grid-template-columns: 2fr 1fr; gap: var(--space-6)">

    {{-- Main Detail --}}
    <div style="display:flex; flex-direction:column; gap: var(--space-6)">

        {{-- Pratinjau Naskah Dinas --}}
        <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center">
                <span class="card-title">Pratinjau Lembar Dokumen</span>
                <span class="badge badge-indigo">Versi v{{ $document->currentVersion->versi ?? 1 }}</span>
            </div>
            <div class="docx-paper-wrapper">
                <x-naskah-preview :document="$document" />
            </div>
        </div>

        {{-- Detail Meta Card --}}
        <div class="card">
            <div class="card-header">
                <span class="card-title">Informasi Dokumen</span>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); font-size:0.875rem">
                <div>
                    <div style="color:var(--text-muted); font-size:0.78rem">PERIHAL</div>
                    <div style="font-weight:500">{{ $document->perihal ?? '-' }}</div>
                </div>
                <div>
                    <div style="color:var(--text-muted); font-size:0.78rem">FORMAT NOMOR TEMPLATE</div>
                    <div style="font-family:monospace; color:var(--brand-700)">{{ $document->documentType->format_nomor }}</div>
                </div>
                <div style="grid-column: span 2">
                    <div style="color:var(--text-muted); font-size:0.78rem">KETERANGAN / CATATAN</div>
                    <div>{{ $document->keterangan ?? 'Tidak ada keterangan tambahan.' }}</div>
                </div>
            </div>
        </div>

        {{-- Upload Versi Baru (jika revisi/draft) --}}
        @if($document->isDraft() || $document->isRevisi())
        <div class="card">
            <div class="card-header">
                <span class="card-title">Unggah Perbaikan / Versi Baru</span>
            </div>
            <form method="POST" action="{{ route('dokumen.upload-versi', $document) }}" enctype="multipart/form-data">
                @csrf
                <div class="form-group">
                    <label for="catatan_revisi" class="form-label">Catatan Perubahan (Versi {{ ($document->currentVersion?->versi ?? 0) + 1 }})</label>
                    <input type="text" name="catatan" id="catatan_revisi" class="form-control" placeholder="mis: Memperbaiki tata bahasa pasal 3 sesuai arahan verifikator">
                </div>
                <div class="form-group">
                    <label class="form-label">Pilih File Baru (.docx)</label>
                    <input type="file" name="file_dokumen" class="form-control" accept=".docx,.doc,.pdf" required>
                </div>
                <button type="submit" class="btn btn-secondary btn-sm">Unggah Versi Baru</button>
            </form>
        </div>
        @endif

        {{-- Riwayat Versi Dokumen --}}
        <div class="card">
            <div class="card-header">
                <span class="card-title">Riwayat Versi Dokumen</span>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Versi</th>
                            <th>Nama File</th>
                            <th>Pengunggah</th>
                            <th>Catatan</th>
                            <th>Waktu</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($document->versions as $v)
                        <tr>
                            <td>
                                <span class="badge {{ $v->is_current ? 'badge-green' : 'badge-gray' }}">
                                    v{{ $v->versi }} {{ $v->is_current ? '(Aktif)' : '' }}
                                </span>
                            </td>
                            <td style="font-weight:500; color:var(--text-primary)">{{ $v->file_name }} ({{ $v->file_size_human }})</td>
                            <td>{{ $v->uploader->name }}</td>
                            <td style="font-size:0.8rem; color:var(--text-muted)">{{ $v->catatan ?? '-' }}</td>
                            <td>{{ $v->created_at->format('d/m/Y H:i') }}</td>
                            <td>
                                @if(in_array($document->status, [\App\Models\Document::STATUS_DITANDATANGANI, \App\Models\Document::STATUS_DIPUBLIKASIKAN, \App\Models\Document::STATUS_DIARSIPKAN]))
                                    <a href="{{ route('dokumen.download-pdf', [$document, $v->id]) }}" class="btn btn-secondary btn-sm">Download PDF</a>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    {{-- Sidebar Timeline & Verifikasi --}}
    <div style="display:flex; flex-direction:column; gap: var(--space-6)">

        {{-- Timeline Status --}}
        <div class="card">
            <div class="card-header">
                <span class="card-title">Alur & Timeline Status</span>
            </div>

            <div class="timeline">
                {{-- Step 1: Draft --}}
                <div class="timeline-item">
                    <div class="timeline-dot" style="background:rgba(34,197,94,0.2); color:#4ade80">✓</div>
                    <div class="timeline-content">
                        <div class="timeline-title">Draft Dibuat</div>
                        <div class="timeline-meta">{{ $document->created_at->format('d/m/Y H:i') }}</div>
                    </div>
                </div>

                {{-- Step 2: Verifikasi — dikelompokkan per level. Kalau satu tahap punya pool
                     verifikator >1 orang (mis. 4 Asesor Internal, salah satu approve = sah),
                     selama semua masih menunggu cukup ditampilkan 1 baris ringkas dibungkus
                     jabatan bersama ("Menunggu salah satu dari 4 · Asesor Internal") — bukan
                     4 baris menunggu yang identik. Begitu ada yang benar-benar bertindak
                     (approve/minta revisi), namanya ditampilkan sendiri; tiket sisanya yang
                     otomatis dibatalkan (kalah cepat) disembunyikan, bukan noise. --}}
                @foreach($document->verifications->groupBy('level') as $level => $levelGroup)
                    @php
                        $decided = $levelGroup->reject(fn ($v) => $v->isMenunggu() || $v->isDibatalkan());
                        $pending = $levelGroup->filter(fn ($v) => $v->isMenunggu());
                    @endphp

                    @foreach($decided as $verif)
                    <div class="timeline-item">
                        @if($verif->isApproved())
                            <div class="timeline-dot" style="background:rgba(34,197,94,0.2); color:#4ade80">✓</div>
                        @elseif($verif->isRevisi())
                            <div class="timeline-dot" style="background:rgba(249,115,22,0.2); color:#fb923c">!</div>
                        @else
                            <div class="timeline-dot" style="background:rgba(234,179,8,0.2); color:#fbbf24">⏳</div>
                        @endif

                        <div class="timeline-content">
                            <div class="timeline-title">
                                Verifikasi Level {{ $level }}: {{ $verif->verifikator->name }}
                            </div>
                            <div class="timeline-meta">
                                Status: <strong>{{ ucfirst($verif->status) }}</strong>
                                @if($verif->direspon_at)
                                    &bull; {{ $verif->direspon_at->format('d/m/Y H:i') }}
                                @endif
                            </div>
                            @if($verif->catatan)
                                <div class="timeline-note">
                                    "{{ $verif->catatan }}"
                                </div>
                            @endif
                            @if($verif->direset_alasan)
                                <div class="timeline-note" style="background:rgba(239,68,68,0.05); border-left-color:#ef4444; color:#ef4444">
                                    <strong>Dikembalikan:</strong> {{ $verif->direset_alasan }}
                                </div>
                            @endif
                        </div>
                    </div>
                    @endforeach

                    @if($pending->count() === 1)
                        @php $verif = $pending->first(); @endphp
                        <div class="timeline-item">
                            <div class="timeline-dot" style="background:rgba(234,179,8,0.2); color:#fbbf24">⏳</div>
                            <div class="timeline-content">
                                <div class="timeline-title">
                                    Verifikasi Level {{ $level }}: {{ $verif->verifikator->name }}
                                </div>
                                <div class="timeline-meta">Status: <strong>Menunggu</strong></div>
                            </div>
                        </div>
                    @elseif($pending->count() > 1)
                        @php
                            $pendingSubs = $pending->map(fn ($v) => $v->verifikator->jabatan ?: $v->verifikator->unit?->nama)->filter()->unique();
                            $pendingCommonSub = $pendingSubs->count() === 1 ? $pendingSubs->first() : null;
                        @endphp
                        <div class="timeline-item">
                            <div class="timeline-dot" style="background:rgba(234,179,8,0.2); color:#fbbf24">⏳</div>
                            <div class="timeline-content">
                                <div class="timeline-title">
                                    Verifikasi Level {{ $level }}
                                    @if($pendingCommonSub)
                                        <span style="font-weight:500; color:var(--text-muted)">&middot; {{ $pendingCommonSub }}</span>
                                    @endif
                                </div>
                                <div class="timeline-meta">Menunggu salah satu dari {{ $pending->count() }} verifikator</div>
                            </div>
                        </div>
                    @endif
                @endforeach

                @if($document->status === \App\Models\Document::STATUS_DITOLAK_TTD || $document->ditolak_ttd_at)
                <div class="timeline-item">
                    <div class="timeline-dot" style="background:rgba(239,68,68,0.2); color:#ef4444">❌</div>
                    <div class="timeline-content">
                        <div class="timeline-title" style="color:var(--text-danger)">Dikembalikan oleh Penandatangan</div>
                        <div class="timeline-meta">{{ $document->ditolak_ttd_at?->format('d/m/Y H:i') }}</div>
                        <div class="timeline-note">"{{ $document->ditolak_ttd_alasan }}"</div>
                    </div>
                </div>
                @endif

                {{-- Tahap pengesahan elektronik internal --}}
                @if($document->signature)
                <div class="timeline-item">
                    <div class="timeline-dot" style="background:rgba(168,85,247,0.2); color:#c084fc">🔏</div>
                    <div class="timeline-content">
                        <div class="timeline-title">Ditandatangani Elektronik</div>
                        <div class="timeline-meta">oleh {{ $document->signature->penandatangan->name }}</div>
                        <div class="timeline-meta" style="font-family:monospace; font-size:0.75rem; color:var(--brand-700); margin-top:2px">
                            Hash: {{ Str::limit($document->signature->hash_dokumen, 20) }}
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>

        {{-- Audit Log --}}
        <div class="card">
            <div class="card-header">
                <span class="card-title">Audit Log Immutable</span>
            </div>
            <div style="display:flex; flex-direction:column; gap:8px; max-height:300px; overflow-y:auto">
                @foreach($document->auditLogs as $log)
                <div style="padding:8px 12px; background:var(--bg-elevated); border-radius:6px; font-size:0.78rem">
                    <div style="display:flex; justify-content:space-between; color:var(--text-muted)">
                        <span><strong>{{ $log->user_name }}</strong> &bull; {{ $log->aksi }}</span>
                        <span>{{ $log->created_at->format('H:i:s d/m') }}</span>
                    </div>
                    <div style="color:var(--text-secondary); margin-top:2px">{{ $log->deskripsi }}</div>
                </div>
                @endforeach
            </div>
        </div>

    </div>

</div>

{{-- Modal Ajukan Dokumen --}}
<div class="modal-overlay" id="modal-ajukan" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Ajukan Dokumen ke Verifikator</div>
            <button type="button" class="btn btn-secondary btn-icon" onclick="document.getElementById('modal-ajukan').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="{{ route('dokumen.ajukan', $document) }}">
            @csrf
            <div class="modal-body">
                @if(!$firstStepInfo['configured'])
                    <div class="alert alert-error" style="margin:0">
                        Jenis naskah ini belum bisa diajukan ke verifikator. Hubungi Admin untuk mengatur alurnya.
                    </div>
                @else
                    @if(!empty($workflowChain['steps']))
                        <div class="workflow-chain" style="margin-bottom: var(--space-4)">
                            @foreach($workflowChain['steps'] as $i => $step)
                                <div class="workflow-chain-step {{ $step['tipe'] === 'penandatangan' ? 'workflow-chain-step-sign' : '' }}">
                                    <div class="workflow-chain-step-badge">{{ $step['tipe'] === 'penandatangan' ? '✓' : (preg_replace('/\D/', '', $step['label']) ?: ($i + 1)) }}</div>
                                    <div class="workflow-chain-step-body">
                                        <div class="workflow-chain-step-label">
                                            <span>{{ $step['label'] }}</span>
                                            @if($step['commonSub'])
                                                <span class="workflow-chain-step-sub">&middot; {{ $step['commonSub'] }}</span>
                                            @endif
                                        </div>
                                        @if($step['note'])
                                            <div class="workflow-chain-step-note {{ !$step['manual'] && empty($step['people']) ? 'is-warning' : '' }}">{{ $step['note'] }}</div>
                                        @endif
                                        @if(!empty($step['people']))
                                            <div class="workflow-chain-people">
                                                @foreach($step['people'] as $person)
                                                    <span class="workflow-chain-person">
                                                        <span>{{ $person['name'] }}</span>
                                                        @if($person['sub'])
                                                            <span class="workflow-chain-person-sub">({{ $person['sub'] }})</span>
                                                        @endif
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($firstStepInfo['needsManual'])
                        <div class="form-group" style="margin-bottom:0">
                            <label for="verifikator_ids_modal" class="form-label">Pilih Verifikator</label>
                            <select name="verifikator_ids[]" id="verifikator_ids_modal" class="form-control" multiple required data-tomselect data-placeholder="Cari & pilih verifikator...">
                                @foreach($verifikators as $v)
                                    <option value="{{ $v->id }}">{{ $v->name }} ({{ $v->jabatan ?? 'Verifikator' }})</option>
                                @endforeach
                            </select>
                            <small style="color:var(--text-muted); font-size:0.78rem; display:block; margin-top:4px">Pilih siapa yang akan memeriksa dokumen ini terlebih dahulu.</small>
                        </div>
                    @else
                        <input type="hidden" name="ajukan_langsung" value="1">
                        <p style="font-size:0.875rem; color:var(--text-secondary); margin:0">
                            Dokumen akan otomatis diproses sesuai alur yang berlaku untuk jenis naskah ini.
                        </p>
                    @endif
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modal-ajukan').style.display='none'">Batal</button>
                @unless(!$firstStepInfo['configured'])
                    <button type="submit" class="btn btn-primary">Kirim Pengajuan</button>
                @endunless
            </div>
        </form>
    </div>
</div>

{{-- Modal Upload Versi Perbaikan --}}
<div class="modal-overlay" id="modal-upload-versi" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Unggah Berkas Naskah Perbaikan</div>
            <button type="button" class="btn btn-secondary btn-icon" onclick="document.getElementById('modal-upload-versi').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="{{ route('dokumen.upload-versi', $document) }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-body">
                <p style="font-size:0.875rem; color:var(--text-secondary); margin-bottom:1rem">
                    Unggah file perbaikan naskah dinas dalam format <code>.docx</code> yang telah disunting.
                </p>
                <div class="form-group" style="margin-bottom:1rem">
                    <label for="file_dokumen" class="form-label">Berkas Naskah Baru (.docx)</label>
                    <input type="file" name="file_dokumen" id="file_dokumen" class="form-control" accept=".docx,.doc" required>
                </div>
                <div class="form-group">
                    <label for="catatan_revisi" class="form-label">Catatan Perbaikan</label>
                    <textarea name="catatan" id="catatan_revisi" class="form-control" rows="3" placeholder="Contoh: Perbaikan tata bahasa dan perbaikan tabel perihal..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modal-upload-versi').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Versi Baru</button>
            </div>
        </form>
    </div>
</div>
@endsection
