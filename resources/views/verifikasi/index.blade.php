@extends('layouts.app')

@section('title', 'Antrian Verifikasi')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Antrian Verifikasi</span>
@endsection

@section('content')

<div class="page-header">
    <h1 class="page-title">Antrian Verifikasi Saya</h1>
    <p class="page-subtitle">Daftar naskah dinas yang membutuhkan peninjauan dan persetujuan Anda</p>
</div>

{{-- Filter Panel --}}
<div class="card" style="margin-bottom: var(--space-6); padding: var(--space-4); background: #f8fafc; border: 1px solid #e2e8f0;">
    <form method="GET" action="{{ route('verifikasi.index') }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
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
            <label style="font-size: 0.78rem; font-weight: 700; color: #475569; margin-bottom: 4px; display: block;">Unit Kerja / Instalasi</label>
            <select name="unit_id" class="form-control" style="font-size: 0.85rem; padding: 6px 10px;">
                <option value="">-- Semua Unit Kerja --</option>
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
                <a href="{{ route('verifikasi.index') }}" class="btn btn-secondary" style="font-size: 0.85rem; padding: 7px 14px;">Reset</a>
            @endif
        </div>
    </form>
</div>

<div class="card" style="margin-bottom: var(--space-8)">
    <div class="card-header">
        <span class="card-title">Menunggu Verifikasi ({{ $antrian->total() }})</span>
    </div>

    @if($antrian->isEmpty())
        <div class="empty-state">
            <div class="empty-state-icon">✓</div>
            <div class="empty-state-title">Semua Bersih!</div>
            <div class="empty-state-text">Saat ini tidak ada naskah dinas yang menunggu verifikasi Anda.</div>
        </div>
    @else
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Dokumen</th>
                        <th>Pengusul / Unit</th>
                        <th>Level</th>
                        <th>Batas Waktu (SLA)</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($antrian as $v)
                    <tr>
                        <td>
                            <div style="font-weight:600; color:var(--text-primary)">{{ $v->document->judul }}</div>
                            <div style="font-size:0.75rem; color:var(--brand-400)">{{ $v->document->documentType->nama }}</div>
                            <div style="font-size:0.72rem; color:#d97706; font-family:monospace; margin-top:2px">[DRAFT - Belum TTE]</div>
                        </td>
                        <td>
                            <div>{{ $v->document->pengusul->name }}</div>
                            <div style="font-size:0.75rem; color:var(--text-muted)">{{ $v->document->unit->nama }}</div>
                        </td>
                        <td><span class="badge badge-indigo">Level {{ $v->level }}</span></td>
                        <td>
                            @if($v->isOverdue())
                                <span class="badge badge-red">Terlambat ({{ $v->batas_waktu?->format('d/m/Y') }})</span>
                            @else
                                <span class="badge badge-yellow">{{ $v->batas_waktu?->format('d/m/Y') ?? '2 Hari' }}</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('verifikasi.show', $v) }}" class="btn btn-primary btn-sm">
                                Review Dokumen
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

{{-- Riwayat --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Riwayat Verifikasi Saya</span>
    </div>

    @if($riwayat->isEmpty())
        <div style="padding: 1.5rem; text-align:center; color:var(--text-muted); font-size:0.875rem">
            Belum ada riwayat verifikasi.
        </div>
    @else
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Dokumen</th>
                        <th>Pengusul</th>
                        <th>Keputusan</th>
                        <th>Catatan</th>
                        <th>Tanggal Respon</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($riwayat as $r)
                    <tr>
                        <td style="font-weight:500; color:var(--text-primary)">{{ $r->document->judul }}</td>
                        <td>{{ $r->document->pengusul->name }}</td>
                        <td>
                            @if($r->isApproved())
                                <span class="badge badge-green">Disetujui</span>
                            @else
                                <span class="badge badge-orange">Minta Revisi</span>
                            @endif
                        </td>
                        <td style="font-size:0.8rem; color:var(--text-muted)">{{ $r->catatan ?? '-' }}</td>
                        <td>{{ $r->direspon_at?->format('d/m/Y H:i') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@endsection
