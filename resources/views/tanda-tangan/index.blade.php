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
                        <td style="font-weight:600; color:var(--text-primary)">{{ $doc->judul }}</td>
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
