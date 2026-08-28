@extends('layouts.app')

@section('title', 'Dashboard')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Dashboard</span>
@endsection

@section('content')

{{-- Page Header --}}
<div class="page-header" style="display:flex; align-items:center; justify-content:space-between">
    <div>
        <h1 class="page-title">Selamat datang, {{ Str::words(auth()->user()->name, 2, '') }} 👋</h1>
        <p class="page-subtitle">{{ now()->translatedFormat('l, d F Y') }} &mdash; {{ auth()->user()->jabatan ?? auth()->user()->getRoleNames()->first() }}</p>
    </div>
    <a href="{{ route('dokumen.create') }}" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        Buat Dokumen
    </a>
</div>

{{-- Stats Grid --}}
<div class="stats-grid">
    <div class="stat-card stat-card-blue fade-in">
        <div class="stat-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-value">{{ $stats['total_dokumen'] }}</div>
            <div class="stat-label">Total Dokumen Saya</div>
        </div>
    </div>

    <div class="stat-card stat-card-orange fade-in" style="animation-delay: 0.05s">
        <div class="stat-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-value">{{ $stats['menunggu_tindakan'] }}</div>
            <div class="stat-label">Perlu Tindakan Saya</div>
        </div>
    </div>

    <div class="stat-card stat-card-purple fade-in" style="animation-delay: 0.1s">
        <div class="stat-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
                <polyline points="13 2 13 9 20 9"/>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-value">{{ $stats['draft'] }}</div>
            <div class="stat-label">Masih Draft</div>
        </div>
    </div>

    <div class="stat-card stat-card-green fade-in" style="animation-delay: 0.15s">
        <div class="stat-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-value">{{ $stats['selesai_bulan_ini'] }}</div>
            <div class="stat-label">Selesai Bulan Ini</div>
        </div>
    </div>
</div>

{{-- Main Grid --}}
<div class="dashboard-main-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-6)">

    {{-- Antrian Verifikasi --}}
    @can('dokumen.verifikasi')
    <div class="card dashboard-verification-card fade-in" style="animation-delay:0.2s">
        <div class="card-header">
            <div style="display:flex; align-items:center; gap: var(--space-3)">
                <div style="width:32px;height:32px;background:rgba(234,179,8,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fbbf24">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                </div>
                <span class="card-title">Antrian Verifikasi</span>
            </div>
            <a href="{{ route('verifikasi.index') }}" class="btn btn-secondary btn-sm">Lihat Semua</a>
        </div>

        @if($antrianVerifikasi->isEmpty())
        <div class="empty-state" style="padding: 2rem">
            <div class="empty-state-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            </div>
            <div class="empty-state-title">Tidak ada antrian</div>
            <div class="empty-state-text">Semua dokumen sudah diproses.</div>
        </div>
        @else
        <div style="display:flex; flex-direction:column; gap: var(--space-3)">
            @foreach($antrianVerifikasi as $verif)
            <a href="{{ route('verifikasi.show', $verif) }}" style="display:flex; align-items:center; gap: var(--space-3); padding: var(--space-3); background: var(--bg-elevated); border-radius: var(--radius-md); border: 1px solid var(--border-subtle); transition: all var(--transition-fast); text-decoration:none"
               onmouseover="this.style.borderColor='var(--border-default)'; this.style.background='var(--bg-hover)'"
               onmouseout="this.style.borderColor='var(--border-subtle)'; this.style.background='var(--bg-elevated)'">
                <div style="width:40px;height:40px;background:rgba(99,102,241,0.1);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--brand-600);font-weight:700;font-size:0.75rem">
                    {{ $verif->document->documentType->singkatan }}
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:0.875rem;font-weight:500;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                        {{ $verif->document->judul }}
                    </div>
                    <div style="font-size:0.75rem;color:var(--text-muted)">
                        {{ $verif->document->pengusul->name }} &middot; {{ $verif->created_at->diffForHumans() }}
                    </div>
                </div>
                @if($verif->isOverdue())
                    <span class="badge badge-red">Terlambat</span>
                @else
                    <span class="badge badge-yellow">Menunggu</span>
                @endif
            </a>
            @endforeach
        </div>
        @endif
    </div>
    @endcan

    {{-- Dokumen Saya Terbaru --}}
    <div class="card dashboard-documents-card fade-in" style="animation-delay:0.25s">
        <div class="card-header">
            <div style="display:flex; align-items:center; gap: var(--space-3)">
                <div style="width:32px;height:32px;background:rgba(99,102,241,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--brand-400)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <span class="card-title">Dokumen Saya</span>
            </div>
            <a href="{{ route('dokumen.index') }}" class="btn btn-secondary btn-sm">Lihat Semua</a>
        </div>

        @if($dokumenSaya->isEmpty())
        <div class="empty-state" style="padding: 2rem">
            <div class="empty-state-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div class="empty-state-title">Belum ada dokumen</div>
            <div class="empty-state-text">Mulai dengan membuat dokumen pertama Anda.</div>
            <a href="{{ route('dokumen.create') }}" class="btn btn-primary btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Buat Sekarang
            </a>
        </div>
        @else
        <div style="display:flex; flex-direction:column; gap: var(--space-3)">
            @foreach($dokumenSaya as $doc)
            <a href="{{ route('dokumen.show', $doc) }}" style="display:flex; align-items:center; gap: var(--space-3); padding: var(--space-3); background: var(--bg-elevated); border-radius: var(--radius-md); border: 1px solid var(--border-subtle); transition: all var(--transition-fast); text-decoration:none"
               onmouseover="this.style.borderColor='var(--border-default)'; this.style.background='var(--bg-hover)'"
               onmouseout="this.style.borderColor='var(--border-subtle)'; this.style.background='var(--bg-elevated)'">
                <div style="width:40px;height:40px;background:rgba(99,102,241,0.1);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--brand-600);font-weight:700;font-size:0.75rem">
                    {{ $doc->documentType->singkatan }}
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:0.875rem;font-weight:500;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                        {{ $doc->judul }}
                    </div>
                    <div style="font-size:0.75rem;color:var(--text-muted)">{{ $doc->updated_at->diffForHumans() }}</div>
                </div>
                @php
                    $colorMap = ['gray'=>'badge-gray','blue'=>'badge-blue','yellow'=>'badge-yellow','orange'=>'badge-orange','purple'=>'badge-purple','green'=>'badge-green','teal'=>'badge-teal','indigo'=>'badge-indigo','red'=>'badge-red'];
                @endphp
                <span class="badge {{ $colorMap[$doc->status_color] ?? 'badge-gray' }}">{{ $doc->status_label }}</span>
            </a>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Dokumen yang dikembalikan untuk diperbaiki oleh pengusul --}}
    @if($dokumenRevisi->isNotEmpty())
    <div class="card dashboard-revision-card fade-in" style="animation-delay:0.28s">
    <div class="card-header">
        <div style="display:flex; align-items:center; gap: var(--space-3)">
            <div style="width:32px;height:32px;background:rgba(249,115,22,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#f97316">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.7 2.8 17a2 2 0 0 0 1.7 3h15a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg>
            </div>
            <div>
                <span class="card-title">Perlu Revisi</span>
                <div class="card-helper-text">Perbaiki dokumen berdasarkan catatan verifikator</div>
            </div>
        </div>
        <a href="{{ route('dokumen.index', ['status' => 'revisi']) }}" class="btn btn-warning btn-sm">Lihat Dokumen</a>
    </div>
    <div class="dashboard-revision-list">
        @foreach($dokumenRevisi as $doc)
        <a href="{{ route('dokumen.show', $doc) }}" class="dashboard-revision-item">
            <div class="dashboard-doc-type">{{ $doc->documentType->singkatan }}</div>
            <div class="dashboard-revision-content">
                <strong>{{ $doc->judul }}</strong>
                <small>Dikembalikan {{ $doc->updated_at->diffForHumans() }}</small>
            </div>
            <span class="badge badge-orange">Perlu Revisi</span>
        </a>
        @endforeach
    </div>
    </div>
    @endif

    {{-- Antrian Pengesahan --}}
@can('dokumen.tanda_tangan')
@if($antrianTtd->isNotEmpty())
<div class="card dashboard-ttd-card fade-in" style="animation-delay:0.3s">
    <div class="card-header">
        <div style="display:flex; align-items:center; gap: var(--space-3)">
            <div style="width:32px;height:32px;background:rgba(168,85,247,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#c084fc">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/></svg>
            </div>
            <span class="card-title">Menunggu Pengesahan Saya</span>
        </div>
        <a href="{{ route('ttd.index') }}" class="btn btn-secondary btn-sm">Lihat Semua</a>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Dokumen</th>
                    <th>Jenis</th>
                    <th>Pengusul</th>
                    <th>Diajukan</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach($antrianTtd as $doc)
                <tr>
                    <td style="color:var(--text-primary); font-weight:500">{{ Str::limit($doc->judul, 40) }}</td>
                    <td><span class="badge badge-indigo">{{ $doc->documentType->singkatan }}</span></td>
                    <td>{{ $doc->pengusul->name }}</td>
                    <td>{{ $doc->diajukan_at?->format('d/m/Y') }}</td>
                    <td>
                        <a href="{{ route('ttd.show', $doc) }}" class="btn btn-warning btn-sm">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/></svg>
                            Tandatangani
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endcan

</div>

@endsection
