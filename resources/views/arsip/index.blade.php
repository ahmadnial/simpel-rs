@extends('layouts.app')

@section('title', 'Arsip Digital')

@section('breadcrumb')
<span class="breadcrumb-separator">/</span>
<span class="breadcrumb-current">Arsip Digital</span>
@endsection

@section('content')

<div class="page-header">
    <h1 class="page-title">Portal Arsip Dokumen RS Nur Rohmah</h1>
    <p class="page-subtitle">Pencarian dan repositori seluruh dokumen resmi rumah sakit</p>
</div>

{{-- Search & Filter Bar --}}
<div class="card" style="margin-bottom: var(--space-6)">
    <form method="GET" action="{{ route('arsip.index') }}" class="filter-bar" style="margin-bottom:0">
        <div class="search-bar" style="flex:1; min-width: 250px">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8" />
                <line x1="21" y1="21" x2="16.65" y2="16.65" />
            </svg>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari judul, nomor surat, atau perihal...">
        </div>

        <select name="document_type_id" class="form-control" style="width: auto" onchange="this.form.submit()">
            <option value="">Semua Jenis Naskah</option>
            @foreach($documentTypes as $t)
            <option value="{{ $t->id }}" {{ request('document_type_id') == $t->id ? 'selected' : '' }}>{{ $t->nama }}</option>
            @endforeach
        </select>

        <select name="unit_id" class="form-control" style="width: auto" onchange="this.form.submit()">
            <option value="">Semua Unit / Instalasi</option>
            @foreach($units as $u)
            <option value="{{ $u->id }}" {{ request('unit_id') == $u->id ? 'selected' : '' }}>{{ $u->nama }}</option>
            @endforeach
        </select>

        <button type="submit" class="btn btn-secondary">Cari</button>
    </form>
</div>

<div class="card">
    @if($documents->isEmpty())
    <div class="empty-state">
        <div class="empty-state-icon">📁</div>
        <div class="empty-state-title">Arsip Tidak Ditemukan</div>
        <div class="empty-state-text">Tidak ada dokumen resmi yang cocok dengan kriteria pencarian atau hak akses Anda.</div>
    </div>
    @else
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nomor Surat</th>
                    <th>Judul Naskah</th>
                    <th>Jenis</th>
                    <th>Unit / Instalasi Pengusul</th>
                    <th>Akses / Visibilitas</th>
                    <th>Keabsahan</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach($documents as $doc)
                <tr>
                    <td style="font-family:monospace; font-weight:700; color:var(--brand-300)">
                        {{ $doc->nomor_surat ?? '-' }}
                    </td>
                    <td style="font-weight:600; color:var(--text-primary)">
                        <a href="{{ route('arsip.show', $doc) }}" style="color:inherit; text-decoration:none">{{ $doc->judul }}</a>
                    </td>
                    <td><span class="badge badge-indigo">{{ $doc->documentType->singkatan }}</span></td>
                    <td>{{ $doc->unit->nama }}</td>
                    <td>
                        @if($doc->visibility_scope === 'terbatas' || $doc->is_rahasia)
                        <span class="badge badge-red">🔒 Rahasia / Terbatas</span>
                        @elseif($doc->visibility_scope === 'unit')
                        <span class="badge badge-yellow" title="{{ $doc->distributions->pluck('unit.nama')->implode(', ') }}">🏢 {{ $doc->distributions->count() }} Unit Terkait</span>
                        @else
                        <span class="badge badge-green">🌐 Publik Internal RS</span>
                        @endif
                    </td>
                    <td>
                        @if($doc->signature)
                        <span class="badge badge-green">TTE Sah (SHA-256)</span>
                        @else
                        <span class="badge badge-gray">Draft</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('arsip.show', $doc) }}" class="btn btn-secondary btn-sm">Lihat Detail</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div style="margin-top: var(--space-4)">{{ $documents->withQueryString()->links() }}</div>
    @endif
</div>

@endsection