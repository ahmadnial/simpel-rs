@extends('layouts.app')

@section('title', 'Panel Administrator')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Panel Admin</span>
@endsection

@section('content')

<div class="page-header">
    <h1 class="page-title">Panel Administrator SIMPEL-RS</h1>
    <p class="page-subtitle">Kelola unit, pengguna, hak akses, klasifikasi naskah, dan alur persetujuan</p>
</div>

<div class="stats-grid">
    <a href="{{ route('admin.users.index') }}" class="stat-card stat-card-blue" style="text-decoration:none;">
        <div class="stat-icon">👤</div>
        <div class="stat-body">
            <div class="stat-value">{{ $stats['total_users'] }}</div>
            <div class="stat-label">Kelola Pengguna & Akun &rarr;</div>
        </div>
    </a>
    <a href="{{ route('admin.units.index') }}" class="stat-card stat-card-purple" style="text-decoration:none;">
        <div class="stat-icon">🏢</div>
        <div class="stat-body">
            <div class="stat-value">{{ $stats['total_units'] }}</div>
            <div class="stat-label">Kelola Unit Kerja &rarr;</div>
        </div>
    </a>
    <a href="{{ route('admin.jenis-naskah.index') }}" class="stat-card stat-card-green" style="text-decoration:none;">
        <div class="stat-icon">📋</div>
        <div class="stat-body">
            <div class="stat-value">{{ $stats['total_types'] }}</div>
            <div class="stat-label">Kelola Jenis Naskah &rarr;</div>
        </div>
    </a>
    <a href="{{ route('admin.workflows.index') }}" class="stat-card stat-card-orange" style="text-decoration:none;">
        <div class="stat-icon">⚙️</div>
        <div class="stat-body">
            <div class="stat-value">{{ $stats['total_workflows'] }}</div>
            <div class="stat-label">Kelola Alur Persetujuan &rarr;</div>
        </div>
    </a>
</div>

<div style="display:grid; grid-template-columns: 2fr 1fr; gap: var(--space-6)">

    {{-- Master Users --}}
    <div class="card">
        <div class="card-header">
            <span class="card-title">Daftar Pengguna Sistem</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Nama & NIP</th>
                        <th>Email</th>
                        <th>Jabatan / Unit</th>
                        <th>Role</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $u)
                    <tr>
                        <td style="font-weight:600; color:var(--text-primary)">
                            {{ $u->name }}
                            @if($u->nip) <div style="font-size:0.75rem; color:var(--text-muted); font-family:monospace">NIP: {{ $u->nip }}</div> @endif
                        </td>
                        <td>{{ $u->email }}</td>
                        <td>{{ $u->jabatan ?? '-' }} <div style="font-size:0.75rem; color:var(--brand-300)">{{ $u->unit?->nama ?? '-' }}</div></td>
                        <td><span class="badge badge-purple">{{ $u->getRoleNames()->first() ?? 'user' }}</span></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Master Units & Jenis --}}
    <div style="display:flex; flex-direction:column; gap: var(--space-6)">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Jenis Dokumen & Format Nomor</span>
            </div>
            <div style="display:flex; flex-direction:column; gap:10px">
                @foreach($documentTypes as $dt)
                <div style="padding:10px; background:var(--bg-elevated); border-radius:8px; font-size:0.85rem">
                    <div style="font-weight:700; color:var(--text-primary)">[{{ $dt->singkatan }}] {{ $dt->nama }}</div>
                    <div style="font-family:monospace; font-size:0.75rem; color:var(--brand-300); margin-top:2px">{{ $dt->format_nomor }}</div>
                </div>
                @endforeach
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Aktivitas Audit Log Sistem</span>
            </div>
            <div style="display:flex; flex-direction:column; gap:8px; font-size:0.78rem">
                @foreach($auditLogs as $al)
                <div style="padding:8px 10px; background:var(--bg-elevated); border-radius:6px">
                    <div style="color:var(--brand-400); font-weight:600">{{ $al->user_name }} &bull; {{ $al->aksi }}</div>
                    <div style="color:var(--text-muted)">{{ $al->deskripsi }}</div>
                    <div style="color:var(--text-disabled); font-size:0.7rem; text-align:right">{{ $al->created_at->format('d/m H:i') }}</div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

</div>

@endsection
