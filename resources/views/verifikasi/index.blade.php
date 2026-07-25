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
