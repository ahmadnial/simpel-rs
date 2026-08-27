@extends('layouts.app')

@section('title', 'Antrian Tanda Tangan')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Antrian TTE</span>
@endsection

@section('content')

<div class="page-header">
    <h1 class="page-title">Antrian Tanda Tangan Elektronik (TTE)</h1>
    <p class="page-subtitle">Daftar naskah dinas yang telah lolos verifikasi dan siap Anda sahkan</p>
</div>

{{-- Filter Panel --}}
<div class="card" style="margin-bottom: var(--space-6); padding: var(--space-4); background: #f8fafc; border: 1px solid #e2e8f0;">
    <form method="GET" action="{{ route('ttd.index') }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
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
                <a href="{{ route('ttd.index') }}" class="btn btn-secondary" style="font-size: 0.85rem; padding: 7px 14px;">Reset</a>
            @endif
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">Menunggu Tanda Tangan ({{ $antrian->total() }})</span>
    </div>

    @if($antrian->isEmpty())
        <div class="empty-state">
            <div class="empty-state-icon">🔏</div>
            <div class="empty-state-title">Tidak ada antrian TTE</div>
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
                            <div style="font-size:0.72rem; color:#d97706; font-family:monospace; margin-top:2px">[DRAFT - Menunggu TTE Direktur]</div>
                        </td>
                        <td><span class="badge badge-purple">{{ $doc->documentType->nama }}</span></td>
                        <td>{{ $doc->pengusul->name }} &bull; {{ $doc->unit->nama }}</td>
                        <td>{{ $doc->updated_at->format('d/m/Y H:i') }}</td>
                        <td>
                            <a href="{{ route('ttd.show', $doc) }}" class="btn btn-warning btn-sm">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/></svg>
                                Prosedur TTE
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

@endsection
