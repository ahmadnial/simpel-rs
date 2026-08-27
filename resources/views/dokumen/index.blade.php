@extends('layouts.app')

@section('title', 'Dokumen Saya')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Dokumen Saya</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; align-items:center; justify-content:space-between">
    <div>
        <h1 class="page-title">Dokumen Saya</h1>
        <p class="page-subtitle">Daftar draft naskah dinas dan dokumen yang diajukan</p>
    </div>
    <a href="{{ route('dokumen.create') }}" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Buat Dokumen Baru
    </a>
</div>

{{-- Search & Filter --}}
<div class="card" style="margin-bottom: var(--space-6)">
    <form method="GET" action="{{ route('dokumen.index') }}" class="filter-bar" style="margin-bottom:0">
        <div class="search-bar" style="flex:1; min-width: 250px">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari judul dokumen...">
        </div>

        <select name="status" class="form-control" style="width: auto; min-width: 160px" onchange="this.form.submit()">
            <option value="">Semua Status</option>
            <option value="draft" {{ request('status') == 'draft' ? 'selected' : '' }}>Draft</option>
            <option value="diajukan" {{ request('status') == 'diajukan' ? 'selected' : '' }}>Diajukan</option>
            <option value="dalam_verifikasi" {{ request('status') == 'dalam_verifikasi' ? 'selected' : '' }}>Dalam Verifikasi</option>
            <option value="revisi" {{ request('status') == 'revisi' ? 'selected' : '' }}>Perlu Revisi</option>
            <option value="menunggu_ttd" {{ request('status') == 'menunggu_ttd' ? 'selected' : '' }}>Menunggu TTD</option>
            <option value="ditandatangani" {{ request('status') == 'ditandatangani' ? 'selected' : '' }}>Ditandatangani</option>
        </select>

        <button type="submit" class="btn btn-secondary">Filter</button>
        @if(request()->anyFilled(['search', 'status']))
            <a href="{{ route('dokumen.index') }}" class="btn btn-secondary btn-icon" title="Reset filter">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </a>
        @endif
    </form>
</div>

{{-- Table --}}
<div class="card">
    @if($documents->isEmpty())
        <div class="empty-state">
            <div class="empty-state-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div class="empty-state-title">Belum ada dokumen</div>
            <div class="empty-state-text">Tidak ada dokumen yang sesuai dengan kriteria pencarian.</div>
            <a href="{{ route('dokumen.create') }}" class="btn btn-primary btn-sm">Buat Dokumen Baru</a>
        </div>
    @else
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Jenis</th>
                        <th>Judul Dokumen</th>
                        <th>Status</th>
                        <th>Versi</th>
                        <th>Terakhir Diubah</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($documents as $doc)
                    <tr>
                        <td>
                            <span class="badge badge-indigo" style="font-weight:700">{{ $doc->documentType->singkatan }}</span>
                        </td>
                        <td>
                            <a href="{{ route('dokumen.show', $doc) }}" style="color:var(--text-primary); font-weight:600; text-decoration:none" class="hover-underline">
                                {{ $doc->judul }}
                            </a>
                            @if($doc->nomor_surat)
                                <div style="font-size:0.75rem; color:var(--brand-700); font-family:monospace; margin-top:2px">
                                    {{ $doc->nomor_surat }}
                                </div>
                            @else
                                <div style="font-size:0.72rem; color:#d97706; font-family:monospace; margin-top:2px">
                                    [DRAFT - Belum TTE]
                                </div>
                            @endif
                        </td>
                        <td>
                            @php
                                $colorMap = ['gray'=>'badge-gray','blue'=>'badge-blue','yellow'=>'badge-yellow','orange'=>'badge-orange','purple'=>'badge-purple','green'=>'badge-green','teal'=>'badge-teal','indigo'=>'badge-indigo','red'=>'badge-red'];
                            @endphp
                            <span class="badge {{ $colorMap[$doc->status_color] ?? 'badge-gray' }}">{{ $doc->status_label }}</span>
                        </td>
                        <td>v{{ $doc->currentVersion->versi ?? 1 }}</td>
                        <td>{{ $doc->updated_at->diffForHumans() }}</td>
                        <td>
                            <div style="display:flex; gap:6px">
                                <a href="{{ route('dokumen.show', $doc) }}" class="btn btn-secondary btn-sm">
                                    Detail
                                </a>
                                @if($doc->currentVersion && in_array($doc->status, [\App\Models\Document::STATUS_DITANDATANGANI, \App\Models\Document::STATUS_DIPUBLIKASIKAN, \App\Models\Document::STATUS_DIARSIPKAN]))
                                    <a href="{{ route('dokumen.download-pdf', $doc) }}" class="btn btn-secondary btn-sm btn-icon" title="Unduh PDF Resmi">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M12 18v-6"/><path d="m9 15 3 3 3-3"/></svg>
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="margin-top: var(--space-4)">
            {{ $documents->withQueryString()->links() }}
        </div>
    @endif
</div>

@endsection
