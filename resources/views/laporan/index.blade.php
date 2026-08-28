@extends('layouts.app')

@section('title', 'Laporan & Statistik')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Laporan & Statistik</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; align-items:center; justify-content:space-between">
    <div>
        <h1 class="page-title">Laporan & Statistik Persuratan</h1>
        <p class="page-subtitle">Statistik tata kelola naskah dinas dan rekapitulasi data akreditasi rumah sakit</p>
    </div>
    <a href="{{ route('laporan.ekspor', ['tahun' => $tahun, 'unit_id' => $unitId]) }}" class="btn btn-success">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Ekspor Excel (.xlsx)
    </a>
</div>

{{-- Filter --}}
<div class="card" style="margin-bottom: var(--space-6)">
    <form method="GET" action="{{ route('laporan.index') }}" class="filter-bar" style="margin-bottom:0">
        <select name="tahun" class="form-control" style="width: auto" onchange="this.form.submit()">
            @for($y = date('Y'); $y >= date('Y') - 3; $y--)
                <option value="{{ $y }}" {{ $tahun == $y ? 'selected' : '' }}>Tahun {{ $y }}</option>
            @endfor
        </select>

        @role('super_admin')
        <select name="unit_id" class="form-control" style="width: auto" onchange="this.form.submit()">
            <option value="">Semua Unit / Instalasi</option>
            @foreach($units as $u)
                <option value="{{ $u->id }}" {{ $unitId == $u->id ? 'selected' : '' }}>{{ $u->nama }}</option>
            @endforeach
        </select>
        @endrole
    </form>
</div>

{{-- Stats Grid --}}
<div class="stats-grid">
    <div class="stat-card stat-card-blue">
        <div class="stat-icon">📄</div>
        <div class="stat-body">
            <div class="stat-value">{{ $totalDokumen }}</div>
            <div class="stat-label">Total Dokumen</div>
        </div>
    </div>
    <div class="stat-card stat-card-green">
        <div class="stat-icon">✓</div>
        <div class="stat-body">
            <div class="stat-value">{{ $totalSelesai }}</div>
            <div class="stat-label">Selesai & Disahkan Internal</div>
        </div>
    </div>
    <div class="stat-card stat-card-orange">
        <div class="stat-icon">⏳</div>
        <div class="stat-body">
            <div class="stat-value">{{ $totalProses }}</div>
            <div class="stat-label">Sedang Dalam Proses</div>
        </div>
    </div>
    <div class="stat-card stat-card-purple">
        <div class="stat-icon">✍</div>
        <div class="stat-body">
            <div class="stat-value">{{ $totalRevisi }}</div>
            <div class="stat-label">Pernah Diminta Revisi</div>
        </div>
    </div>
</div>

{{-- Breakdown per Jenis Naskah --}}
<div class="card" style="margin-bottom: var(--space-6)">
    <div class="card-header">
        <span class="card-title">Sebaran Dokumen berdasarkan Jenis</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Jenis Naskah</th>
                    <th>Format Nomor Surat</th>
                    <th>Jumlah Dokumen</th>
                </tr>
            </thead>
            <tbody>
                @foreach($dokumenPerJenis as $t)
                <tr>
                    <td><span class="badge badge-indigo">{{ $t->singkatan }}</span></td>
                    <td style="font-weight:600; color:var(--text-primary)">{{ $t->nama }}</td>
                    <td style="font-family:monospace; color:var(--brand-300)">{{ $t->format_nomor }}</td>
                    <td style="font-weight:700; color:var(--brand-400)">{{ $t->documents_count }} Dokumen</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@endsection
