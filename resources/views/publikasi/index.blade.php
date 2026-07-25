@extends('layouts.app')

@section('title', 'Publikasi & Distribusi')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Publikasi</span>
@endsection

@section('content')

<div class="page-header">
    <h1 class="page-title">Publikasi & Distribusi Naskah Dinas</h1>
    <p class="page-subtitle">Kelola dan publikasikan naskah dinas yang telah disahkan ke portal internal / unit kerja</p>
</div>

<div class="card" style="margin-bottom: var(--space-8)">
    <div class="card-header">
        <span class="card-title">Siap Dipublikasikan ({{ $siapPublikasi->total() }})</span>
    </div>

    @if($siapPublikasi->isEmpty())
        <div style="padding:2rem; text-align:center; color:var(--text-muted); font-size:0.875rem">
            Tidak ada dokumen bertanda tangan yang menunggu publikasi.
        </div>
    @else
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Nomor Surat</th>
                        <th>Judul Dokumen</th>
                        <th>Jenis Naskah</th>
                        <th>Pengusul</th>
                        <th>Tanggal TTD</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($siapPublikasi as $doc)
                    <tr>
                        <td style="font-family:monospace; font-weight:700; color:var(--brand-300)">{{ $doc->nomor_surat }}</td>
                        <td style="font-weight:600; color:var(--text-primary)">{{ $doc->judul }}</td>
                        <td><span class="badge badge-indigo">{{ $doc->documentType->singkatan }}</span></td>
                        <td>{{ $doc->pengusul->name }}</td>
                        <td>{{ $doc->ditandatangani_at?->format('d/m/Y H:i') }}</td>
                        <td>
                            <form method="POST" action="{{ route('publikasi.publikasi', $doc) }}">
                                @csrf
                                <button type="submit" class="btn btn-success btn-sm">Publikasikan Sekarang</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- Sudah Dipublikasikan --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Telah Dipublikasikan ({{ $dipublikasikan->total() }})</span>
    </div>

    @if($dipublikasikan->isEmpty())
        <div style="padding:2rem; text-align:center; color:var(--text-muted); font-size:0.875rem">
            Belum ada dokumen yang dipublikasikan.
        </div>
    @else
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Nomor Surat</th>
                        <th>Judul Dokumen</th>
                        <th>Pengusul</th>
                        <th>Waktu Publikasi</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($dipublikasikan as $doc)
                    <tr>
                        <td style="font-family:monospace; font-weight:700; color:var(--brand-300)">{{ $doc->nomor_surat }}</td>
                        <td style="font-weight:600; color:var(--text-primary)">{{ $doc->judul }}</td>
                        <td>{{ $doc->pengusul->name }}</td>
                        <td>{{ $doc->dipublikasikan_at?->format('d/m/Y H:i') }}</td>
                        <td>
                            <a href="{{ route('dokumen.show', $doc) }}" class="btn btn-secondary btn-sm">Lihat Detail</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top: var(--space-4)">{{ $dipublikasikan->links() }}</div>
    @endif
</div>

@endsection
