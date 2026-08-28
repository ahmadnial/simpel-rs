@extends('layouts.app')

@section('title', 'Manajemen Delegasi Wewenang')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Delegasi / Plt</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; align-items:center; justify-content:space-between">
    <div>
        <h1 class="page-title">Delegasi Wewenang (Plt. / Plh.)</h1>
        <p class="page-subtitle">Pelimpahan wewenang persetujuan & tanda tangan sementara saat berhalangan</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('modal-delegasi').style.display='flex'">
        + Tambah Delegasi Baru
    </button>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">Daftar Delegasi Saya</span>
    </div>

    @if($delegations->isEmpty())
        <div class="empty-state">
            <div class="empty-state-icon">👥</div>
            <div class="empty-state-title">Belum ada delegasi aktif</div>
            <div class="empty-state-text">Anda belum menunjuk pejabat pengganti sementara.</div>
        </div>
    @else
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Pejabat Asli</th>
                        <th>Plt. / Plh. Pengganti</th>
                        <th>Tipe</th>
                        <th>Masa Berlaku</th>
                        <th>Alasan</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($delegations as $d)
                    <tr>
                        <td style="font-weight:600; color:var(--text-primary)">{{ $d->pejabat->name }}</td>
                        <td>{{ $d->delegasi->name }} ({{ $d->delegasi->jabatan }})</td>
                        <td><span class="badge badge-purple">{{ strtoupper($d->tipe) }}</span></td>
                        <td>{{ $d->berlaku_dari->format('d/m/Y') }} s/d {{ $d->berlaku_sampai->format('d/m/Y') }}</td>
                        <td>{{ $d->alasan ?? '-' }}</td>
                        <td>
                            @if($d->isCurrentlyActive())
                                <span class="badge badge-green">Aktif</span>
                            @else
                                <span class="badge badge-gray">Non-Aktif</span>
                            @endif
                        </td>
                        <td>
                            @if($d->is_active && $d->pejabat_id === auth()->id())
                                <form method="POST" action="{{ route('delegasi.destroy', $d) }}" style="display:inline" data-confirm="Nonaktifkan pelimpahan wewenang ini?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Nonaktifkan</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top: var(--space-4)">{{ $delegations->links() }}</div>
    @endif
</div>

{{-- Modal Form Delegasi --}}
<div class="modal-overlay" id="modal-delegasi" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Penunjukan Plt. / Plh. Sementara</div>
            <button type="button" class="btn btn-secondary btn-icon" onclick="document.getElementById('modal-delegasi').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="{{ route('delegasi.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label for="delegasi_id" class="form-label">Pilih Pejabat Pengganti (Plt/Plh) <span style="color:#ef4444">*</span></label>
                    <select name="delegasi_id" id="delegasi_id" class="form-control" required>
                        <option value="">-- Pilih Pegawai --</option>
                        @foreach($eligibleUsers as $u)
                            <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->jabatan ?? '-' }}) &mdash; {{ $u->unit?->nama }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label for="tipe" class="form-label">Tipe Pelimpahan Wewenang</label>
                    <select name="tipe" id="tipe" class="form-control" required>
                        <option value="plt">Plt (Pelaksana Tugas)</option>
                        <option value="plh">Plh (Pelaksana Harian)</option>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:var(--space-4)">
                    <div class="form-group">
                        <label for="berlaku_dari" class="form-label">Berlaku Dari <span style="color:#ef4444">*</span></label>
                        <input type="date" name="berlaku_dari" id="berlaku_dari" class="form-control" value="{{ date('Y-m-d') }}" required>
                    </div>
                    <div class="form-group">
                        <label for="berlaku_sampai" class="form-label">Berlaku Sampai <span style="color:#ef4444">*</span></label>
                        <input type="date" name="berlaku_sampai" id="berlaku_sampai" class="form-control" value="{{ date('Y-m-d', strtotime('+7 days')) }}" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="alasan" class="form-label">Alasan Pelimpahan Wewenang</label>
                    <input type="text" name="alasan" id="alasan" class="form-control" placeholder="mis: Dinas Luar / Cuti Tahunan">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modal-delegasi').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Delegasi</button>
            </div>
        </form>
    </div>
</div>

@endsection
