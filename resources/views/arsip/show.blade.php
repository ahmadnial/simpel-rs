@extends('layouts.app')

@section('title', 'Arsip: ' . $document->judul)

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('arsip.index') }}" style="color:var(--text-muted)">Arsip Digital</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">{{ Str::limit($document->judul, 25) }}</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; align-items:flex-start; justify-content:space-between; gap: var(--space-4)">
    <div>
        <div style="display:flex; align-items:center; gap: var(--space-3); margin-bottom: var(--space-2)">
            <span class="badge badge-indigo" style="font-size:0.8rem">{{ $document->documentType->nama }}</span>
            @if($document->is_rahasia || $document->visibility_scope === 'terbatas')
                <span class="badge badge-red">🔒 Rahasia / Terbatas</span>
            @elseif($document->visibility_scope === 'unit')
                <span class="badge badge-yellow">🏢 Internal Unit</span>
            @else
                <span class="badge badge-green">🌐 Publik Internal RS</span>
            @endif
            <span class="badge badge-gray" style="font-family:monospace;">{{ $document->nomor_surat }}</span>
        </div>
        <div style="display:flex; align-items:center; gap:12px;">
            <a href="{{ route('arsip.index') }}" class="btn btn-secondary btn-icon" title="Kembali ke Daftar Arsip" style="padding: 6px; border-radius: 6px; border: 1px solid var(--border-color); background: #fff;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            </a>
            <h1 class="page-title" style="margin:0;">{{ $document->judul }}</h1>
        </div>
        <p class="page-subtitle">
            Unit Pengusul: <strong>{{ $document->unit->nama }}</strong> &bull; Ditetapkan pada {{ $document->ditandatangani_at?->format('d/m/Y') ?? $document->created_at->format('d/m/Y') }}
        </p>
    </div>
</div>

<div style="display:grid; grid-template-columns: 2.5fr 1fr; gap: var(--space-6)">

    {{-- Main Document Viewer --}}
    <div style="display:flex; flex-direction:column; gap: var(--space-6)">
        
        <div class="card" style="box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; background-color:#f8fafc; border-bottom:1px solid #e2e8f0; border-radius: 8px 8px 0 0;">
                <div style="display:flex; align-items:center; gap:8px; color:var(--text-secondary)">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    <span style="font-weight:600; font-size:1rem;">Naskah Dinas Resmi</span>
                </div>
                <div style="font-size:0.8rem; color:#64748b; font-family:monospace">
                    SHA-256: {{ Str::limit($document->signature?->hash_dokumen ?? $document->hash_final ?? 'Belum ada hash', 16) }}
                </div>
            </div>
            
            {{-- Document Viewer / Preview --}}
            <div class="docx-paper-wrapper" style="background:#e2e8f0; padding:20px; border-radius: 0 0 8px 8px; max-height:800px; overflow-y:auto;">
                <x-naskah-preview :document="$document" :hideToolbar="true" />
            </div>
        </div>

    </div>

    {{-- Sidebar Metadata --}}
    <div style="display:flex; flex-direction:column; gap: var(--space-6)">
        
        <div class="card">
            <div class="card-header">
                <span class="card-title">Informasi Arsip</span>
            </div>
            <div style="display:flex; flex-direction:column; gap: 16px; font-size:0.875rem">
                <div>
                    <div style="color:var(--text-muted); font-size:0.75rem; font-weight:600; text-transform:uppercase; margin-bottom:4px;">NOMOR SURAT</div>
                    <div style="font-family:monospace; font-weight:600; font-size:1rem; color:var(--brand-600)">{{ $document->nomor_surat ?? '-' }}</div>
                </div>
                
                <hr style="border:none; border-top:1px dashed #e2e8f0; margin:0">

                <div>
                    <div style="color:var(--text-muted); font-size:0.75rem; font-weight:600; text-transform:uppercase; margin-bottom:4px;">PENANDATANGAN</div>
                    @if($document->signature)
                        <div style="display:flex; align-items:center; gap:8px;">
                            <div style="width:32px; height:32px; border-radius:50%; background:var(--brand-100); color:var(--brand-700); display:flex; align-items:center; justify-content:center; font-weight:700;">
                                {{ substr($document->signature->penandatangan->name, 0, 1) }}
                            </div>
                            <div>
                                <div style="font-weight:600; color:var(--text-primary)">{{ $document->signature->penandatangan->name }}</div>
                                <div style="font-size:0.75rem; color:var(--text-muted)">{{ $document->signature->penandatangan->jabatan ?? 'Direktur Utama' }}</div>
                            </div>
                        </div>
                    @else
                        <span style="color:var(--text-muted)">Belum disahkan secara elektronik.</span>
                    @endif
                </div>
            </div>
        </div>

        @if($document->distributions->count() > 0)
        <div class="card">
            <div class="card-header">
                <span class="card-title">Distribusi Spesifik</span>
            </div>
            <div style="font-size:0.875rem; color:var(--text-secondary);">
                Naskah ini didistribusikan secara khusus ke:
                <ul style="margin-top:8px; padding-left:20px;">
                    @foreach($document->distributions as $dist)
                        <li>{{ $dist->unit->nama }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        @endif

    </div>

</div>

@endsection
