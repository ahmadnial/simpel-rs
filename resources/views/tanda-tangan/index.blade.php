@extends('layouts.app')

@section('title', 'Pengesahan Dokumen')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Pengesahan Dokumen</span>
@endsection

@section('content')

<div class="page-header signature-queue-header">
    <h1 class="page-title">Pengesahan Dokumen</h1>
    <p class="page-subtitle">Proses dokumen yang menunggu pengesahan dan telusuri keputusan yang pernah Anda buat.</p>
</div>

<div class="signature-history-tabs" role="navigation" aria-label="Bagian pengesahan">
    <a href="{{ route('ttd.index', array_merge(request()->except(['tab', 'antrian_page', 'riwayat_page']), ['tab' => 'antrian'])) }}"
       class="signature-history-tab {{ $activeTab === 'antrian' ? 'is-active' : '' }}"
       @if($activeTab === 'antrian') aria-current="page" @endif>
        <span>Menunggu Pengesahan</span>
        <strong>{{ $antrian->total() }}</strong>
    </a>
    <a href="{{ route('ttd.index', array_merge(request()->except(['tab', 'antrian_page', 'riwayat_page']), ['tab' => 'riwayat'])) }}"
       class="signature-history-tab {{ $activeTab === 'riwayat' ? 'is-active' : '' }}"
       @if($activeTab === 'riwayat') aria-current="page" @endif>
        <span>Riwayat Pengesahan Saya</span>
        <strong>{{ $history->total() }}</strong>
    </a>
</div>

{{-- Filter Panel --}}
<div class="card signature-filter-card" style="margin-bottom: var(--space-6); padding: var(--space-4); background: #f8fafc; border: 1px solid #e2e8f0;">
    <form method="GET" action="{{ route('ttd.index') }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="tab" value="{{ $activeTab }}">
        <div style="flex: 1; min-width: 180px;">
            <label style="font-size: 0.78rem; font-weight: 700; color: #475569; margin-bottom: 4px; display: block;">Klasifikasi / Jenis Naskah</label>
            <select name="document_type_id" class="form-control" style="font-size: 0.85rem; padding: 6px 10px;">
                <option value="">-- Semua Jenis Naskah --</option>
                @foreach($documentTypes as $dt)
                    <option value="{{ $dt->id }}" {{ request('document_type_id') == $dt->id ? 'selected' : '' }}>{{ $dt->nama }}</option>
                @endforeach
            </select>
        </div>

        <div style="flex: 1; min-width: 180px;">
            <label style="font-size: 0.78rem; font-weight: 700; color: #475569; margin-bottom: 4px; display: block;">Unit / Instalasi</label>
            <select name="unit_id" class="form-control" style="font-size: 0.85rem; padding: 6px 10px;">
                <option value="">-- Semua Unit / Instalasi --</option>
                @foreach($units as $u)
                    <option value="{{ $u->id }}" {{ request('unit_id') == $u->id ? 'selected' : '' }}>{{ $u->nama }}</option>
                @endforeach
            </select>
        </div>

        <div style="flex: 1.5; min-width: 200px;">
            <label style="font-size: 0.78rem; font-weight: 700; color: #475569; margin-bottom: 4px; display: block;">Pencarian Kata Kunci</label>
            <input type="text" name="search" class="form-control" placeholder="Cari judul, nomor surat, atau pengusul..." value="{{ request('search') }}" style="font-size: 0.85rem; padding: 6px 10px;">
        </div>

        <div style="display: flex; gap: 6px;">
            <button type="submit" class="btn btn-primary" style="font-size: 0.85rem; padding: 7px 14px;">Filter</button>
            @if(request()->hasAny(['document_type_id', 'unit_id', 'search']))
                <a href="{{ route('ttd.index', ['tab' => $activeTab]) }}" class="btn btn-secondary" style="font-size: 0.85rem; padding: 7px 14px;">Reset</a>
            @endif
        </div>
    </form>
</div>

@if($activeTab === 'antrian')
<div class="card signature-queue-card">
    <div class="card-header">
        <span class="card-title">Menunggu Pengesahan ({{ $antrian->total() }})</span>
    </div>

    @if($antrian->isEmpty())
        <div class="empty-state">
            <div class="empty-state-icon">🔏</div>
            <div class="empty-state-title">Tidak ada antrian pengesahan</div>
            <div class="empty-state-text">Saat ini tidak ada dokumen yang menunggu pengesahan Anda.</div>
        </div>
    @else
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Dokumen</th>
                        <th>Jenis Naskah</th>
                        <th>Pengusul / Unit</th>
                        <th>Tanggal Lolos Verifikasi</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($antrian as $doc)
                    <tr>
                        <td style="font-weight:600; color:var(--text-primary)">
                            {{ $doc->judul }}
                            <div style="font-size:0.72rem; color:#d97706; font-family:monospace; margin-top:2px">[DRAFT - Menunggu Pengesahan Internal]</div>
                        </td>
                        <td><span class="badge badge-purple">{{ $doc->documentType->nama }}</span></td>
                        <td>{{ $doc->pengusul->name }} &bull; {{ $doc->unit->nama }}</td>
                        <td>{{ $doc->updated_at->format('d/m/Y H:i') }}</td>
                        <td>
                            <a href="{{ route('ttd.show', $doc) }}" class="btn btn-warning btn-sm">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/></svg>
                                Proses Pengesahan
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top: var(--space-4)">{{ $antrian->links() }}</div>
    @endif
</div>
@else
<div class="signature-history-summary" aria-label="Ringkasan riwayat pengesahan">
    <div class="signature-history-summary-item">
        <span class="badge badge-green">Disahkan</span>
        <strong>{{ $historyCounts['signed'] }}</strong>
        <small>dokumen telah Anda sahkan</small>
    </div>
    <div class="signature-history-summary-item">
        <span class="badge badge-orange">Dikembalikan</span>
        <strong>{{ $historyCounts['returned'] }}</strong>
        <small>keputusan dikembalikan ke verifikator</small>
    </div>
</div>

<div class="card signature-queue-card">
    <div class="card-header">
        <div>
            <span class="card-title">Riwayat Pengesahan Saya ({{ $history->total() }})</span>
            <p class="signature-history-help">Keputusan menunjukkan tindakan Anda saat itu. Status terkini menunjukkan posisi dokumen sekarang.</p>
        </div>
    </div>

    @if($history->isEmpty())
        <div class="empty-state">
            <div class="empty-state-icon">📋</div>
            <div class="empty-state-title">Belum ada riwayat pengesahan</div>
            <div class="empty-state-text">Dokumen yang Anda sahkan atau kembalikan ke verifikator akan tercatat di sini.</div>
        </div>
    @else
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Dokumen</th>
                        <th>Keputusan Anda</th>
                        <th>Pengusul / Unit</th>
                        <th>Catatan</th>
                        <th>Waktu Keputusan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($history as $event)
                        @php($doc = $event->document)
                        @continue(!$doc)
                        <tr>
                            <td>
                                <div style="font-weight:600; color:var(--text-primary)">{{ $doc->judul }}</div>
                                <div class="signature-history-document-meta">
                                    {{ $doc->nomor_surat ?: 'Belum bernomor' }}
                                    @if($doc->documentType) &bull; {{ $doc->documentType->nama }} @endif
                                </div>
                            </td>
                            <td>
                                @if($event->event_type === 'signed')
                                    <span class="badge badge-green">Disahkan</span>
                                @else
                                    <span class="badge badge-orange">Dikembalikan ke Verifikator</span>
                                @endif
                                <div class="signature-history-current-status">
                                    Status terkini: <span class="badge badge-{{ $doc->status_color }}">{{ $doc->status_label }}</span>
                                </div>
                            </td>
                            <td>
                                <div>{{ $doc->pengusul?->name ?? 'Pengusul tidak tersedia' }}</div>
                                <div class="signature-history-document-meta">{{ $doc->unit?->nama ?? 'Unit tidak tersedia' }}</div>
                            </td>
                            <td>
                                @if($event->event_type === 'returned')
                                    <span class="signature-history-note">{{ $event->note ?: 'Tidak ada catatan.' }}</span>
                                @else
                                    <span class="signature-history-muted">Dokumen disahkan secara elektronik.</span>
                                @endif
                            </td>
                            <td>
                                {{ \Illuminate\Support\Carbon::parse($event->event_at)->format('d/m/Y H:i') }}
                                <div class="signature-history-document-meta">WIB</div>
                            </td>
                            <td>
                                @if($event->event_type === 'signed' && $doc->signature?->qr_token)
                                    <a href="{{ route('public.verify', $doc->signature->qr_token) }}" class="btn btn-secondary btn-sm">Lihat Bukti</a>
                                @elseif($doc->isAccessibleBy(auth()->user()))
                                    <a href="{{ route('dokumen.show', $doc) }}" class="btn btn-secondary btn-sm">Lihat Dokumen</a>
                                @else
                                    <span class="signature-history-muted">Tidak tersedia</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top: var(--space-4)">{{ $history->links() }}</div>
    @endif
</div>
@endif

<style>
    .signature-history-tabs { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; margin-bottom:var(--space-4); }
    .signature-history-tab { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; border:1px solid #dbe3ee; border-radius:10px; background:var(--bg-card, #fff); color:var(--text-secondary); text-decoration:none; font-weight:700; }
    .signature-history-tab strong { display:inline-flex; min-width:30px; height:30px; padding:0 8px; align-items:center; justify-content:center; border-radius:999px; background:#eef2f7; color:#475569; }
    .signature-history-tab.is-active { border-color:var(--primary, #2563eb); box-shadow:0 0 0 2px rgba(37, 99, 235, .1); color:var(--primary, #2563eb); }
    .signature-history-tab.is-active strong { background:#dbeafe; color:#1d4ed8; }
    .signature-history-summary { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; margin-bottom:var(--space-4); }
    .signature-history-summary-item { display:grid; grid-template-columns:auto 1fr; align-items:center; gap:5px 12px; padding:14px 16px; border:1px solid #e2e8f0; border-radius:10px; background:var(--bg-card, #fff); }
    .signature-history-summary-item strong { justify-self:end; font-size:1.35rem; color:var(--text-primary); }
    .signature-history-summary-item small { grid-column:1 / -1; color:var(--text-muted); }
    .signature-history-help { margin:5px 0 0; color:var(--text-muted); font-size:.78rem; font-weight:400; }
    .signature-history-document-meta, .signature-history-muted { margin-top:4px; color:var(--text-muted); font-size:.76rem; }
    .signature-history-current-status { margin-top:8px; color:var(--text-muted); font-size:.74rem; white-space:nowrap; }
    .signature-history-current-status .badge { margin-left:3px; }
    .signature-history-note { display:block; max-width:320px; white-space:normal; color:var(--text-secondary); }
    @media (max-width: 640px) {
        .signature-history-tabs, .signature-history-summary { grid-template-columns:1fr; }
    }
</style>

@endsection
