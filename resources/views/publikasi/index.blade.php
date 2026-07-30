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

@if(session('success'))
    <div style="padding: 12px 16px; background: #f0fdf4; border: 1px solid #86efac; color: #166534; border-radius: 8px; margin-bottom: 20px;">
        {{ session('success') }}
    </div>
@endif

@if(session('error'))
    <div style="padding: 12px 16px; background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; border-radius: 8px; margin-bottom: 20px;">
        {{ session('error') }}
    </div>
@endif

{{-- ═══════════════════════════════════════════════════════ --}}
{{-- SECTION 1: Siap Dipublikasikan                         --}}
{{-- ═══════════════════════════════════════════════════════ --}}
<div class="card" style="margin-bottom: var(--space-8)">
    <div class="card-header">
        <span class="card-title">📋 Siap Dipublikasikan ({{ $siapPublikasi->total() }})</span>
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
                        <th>Pengusul / Unit</th>
                        <th>Status Sifat</th>
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
                        <td>{{ $doc->pengusul->name }} <br><small style="color:#64748b">({{ $doc->unit->nama }})</small></td>
                        <td>
                            @if($doc->is_rahasia)
                                <span class="badge badge-red">🔒 Rahasia</span>
                            @else
                                <span class="badge badge-gray">Biasa</span>
                            @endif
                        </td>
                        <td>{{ $doc->ditandatangani_at?->format('d/m/Y H:i') }}</td>
                        <td>
                            <button class="btn btn-success btn-sm" onclick="openModalPublikasi({{ json_encode($doc) }})">
                                📢 Publikasikan
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top: var(--space-4)">{{ $siapPublikasi->links() }}</div>
    @endif
</div>

{{-- ═══════════════════════════════════════════════════════ --}}
{{-- SECTION 2: Telah Dipublikasikan                        --}}
{{-- ═══════════════════════════════════════════════════════ --}}
<div class="card" style="margin-bottom: var(--space-8)">
    <div class="card-header">
        <span class="card-title">📢 Telah Dipublikasikan ({{ $dipublikasikan->total() }})</span>
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
                        <th>Visibilitas</th>
                        <th>Waktu Publikasi</th>
                        <th style="min-width:180px">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($dipublikasikan as $doc)
                    <tr>
                        <td style="font-family:monospace; font-weight:700; color:var(--brand-300)">{{ $doc->nomor_surat }}</td>
                        <td style="font-weight:600; color:var(--text-primary)">{{ $doc->judul }}</td>
                        <td>{{ $doc->pengusul->name }}</td>
                        <td>
                            @if($doc->visibility_scope === 'terbatas')
                                <span class="badge badge-red">🔒 Terbatas / Rahasia</span>
                            @elseif($doc->visibility_scope === 'unit')
                                <span class="badge badge-yellow">🏢 {{ $doc->distributions->count() }} Unit Terkait</span>
                            @else
                                <span class="badge badge-green">🌐 Publik Internal RS</span>
                            @endif
                        </td>
                        <td>{{ $doc->dipublikasikan_at?->format('d/m/Y H:i') }}</td>
                        <td style="display:flex; gap:6px; flex-wrap:wrap">
                            <a href="{{ route('dokumen.show', $doc) }}" class="btn btn-secondary btn-sm">Lihat</a>
                            <button class="btn btn-danger btn-sm" onclick="openModalUnpublish({{ json_encode($doc) }})">
                                ⏏️ Tarik
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top: var(--space-4)">{{ $dipublikasikan->links() }}</div>
    @endif
</div>

{{-- ═══════════════════════════════════════════════════════ --}}
{{-- SECTION 3: Ditarik dari Publikasi                      --}}
{{-- ═══════════════════════════════════════════════════════ --}}
@if($ditarik->total() > 0)
<div class="card">
    <div class="card-header">
        <span class="card-title">⚠️ Ditarik dari Publikasi ({{ $ditarik->total() }})</span>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nomor Surat</th>
                    <th>Judul Dokumen</th>
                    <th>Alasan Penarikan</th>
                    <th>Dokumen Pengganti</th>
                    <th>Waktu Ditarik</th>
                    <th style="min-width:180px">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach($ditarik as $doc)
                <tr style="background:rgba(251,191,36,0.06)">
                    <td style="font-family:monospace; font-weight:700; color:var(--brand-300)">{{ $doc->nomor_surat }}</td>
                    <td style="font-weight:600; color:var(--text-primary)">{{ $doc->judul }}</td>
                    <td style="max-width:220px; font-size:0.8rem; color:#92400e">
                        {{ $doc->alasan_penarikan }}
                    </td>
                    <td>
                        @if($doc->penggantiDocument)
                            <a href="{{ route('dokumen.show', $doc->penggantiDocument) }}" style="font-size:0.8rem; color:var(--brand-400)">
                                {{ $doc->penggantiDocument->nomor_surat }}
                            </a>
                        @else
                            <span style="color:#94a3b8; font-size:0.8rem">—</span>
                        @endif
                    </td>
                    <td>{{ $doc->ditarik_at?->format('d/m/Y H:i') }}</td>
                    <td style="display:flex; gap:6px; flex-wrap:wrap">
                        <a href="{{ route('dokumen.show', $doc) }}" class="btn btn-secondary btn-sm">Lihat</a>
                        <button class="btn btn-success btn-sm" onclick="openModalRepublish({{ json_encode($doc) }})">
                            🔄 Publikasi Ulang
                        </button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div style="margin-top: var(--space-4)">{{ $ditarik->links() }}</div>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════ --}}
{{-- MODAL: Publikasikan Naskah Dinas                       --}}
{{-- ═══════════════════════════════════════════════════════ --}}
<div id="modalPublikasi" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:550px;">
        <h3 style="margin-top:0; font-size:1.25rem; font-weight:700; color:var(--text-primary);">📢 Publikasikan Naskah Dinas</h3>
        <p id="modalDocTitle" style="font-size:0.875rem; color:#64748b; margin-bottom:16px;"></p>

        <form id="formPublikasi" method="POST">
            @csrf
            <div style="margin-bottom:16px;">
                <label style="font-size:0.875rem; font-weight:600; display:block; margin-bottom:8px;">Tingkat Visibilitas / Hak Akses *</label>

                <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.875rem; cursor:pointer;">
                    <input type="radio" name="visibility_scope" value="internal" id="scope_internal" onchange="toggleUnitList()">
                    <strong>🌐 Publik Internal RS</strong> — Seluruh pegawai RS
                </label>

                <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.875rem; cursor:pointer;">
                    <input type="radio" name="visibility_scope" value="unit" id="scope_unit" onchange="toggleUnitList()">
                    <strong>🏢 Unit Terkait</strong> — Unit pengusul + unit sebar terpilih
                </label>

                <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.875rem; cursor:pointer;">
                    <input type="radio" name="visibility_scope" value="terbatas" id="scope_terbatas" onchange="toggleUnitList()">
                    <strong>🔒 Terbatas / Rahasia</strong> — Hanya unit pengusul & Admin
                </label>
            </div>

            <div id="unitListSection" style="display:none; margin-bottom:16px; padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:6px;">Pilih Unit Kerja Tujuan Distribusi:</label>
                <div style="max-height:150px; overflow-y:auto; display:flex; flex-direction:column; gap:6px;">
                    @foreach($units as $u)
                        <label style="font-size:0.8rem; display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" name="unit_ids[]" value="{{ $u->id }}">
                            {{ $u->nama }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalPublikasi')">Batal</button>
                <button type="submit" class="btn btn-success">Konfirmasi & Publikasikan</button>
            </div>
        </form>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════ --}}
{{-- MODAL: Tarik dari Publikasi (Unpublish)                --}}
{{-- ═══════════════════════════════════════════════════════ --}}
<div id="modalUnpublish" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:550px;">
        <h3 style="margin-top:0; font-size:1.25rem; font-weight:700; color:#dc2626;">⚠️ Tarik Naskah Dinas dari Publikasi</h3>
        <p id="unpubDocTitle" style="font-size:0.875rem; color:#64748b; margin-bottom:4px;"></p>

        <div style="padding:10px 14px; background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; margin-bottom:16px; font-size:0.8rem; color:#991b1b;">
            <strong>Perhatian:</strong> Dokumen yang ditarik tidak lagi dapat diakses publik di Portal Internal / Arsip Digital. Aksi ini tercatat pada Audit Log dan tidak bisa dibatalkan secara diam-diam.
        </div>

        <form id="formUnpublish" method="POST">
            @csrf
            <div style="margin-bottom:16px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Alasan Penarikan *</label>
                <textarea name="alasan_penarikan" class="form-control" rows="3"
                    placeholder="Jelaskan alasan penarikan. Contoh: Terdapat kesalahan data pada pasal 3, digantikan oleh SK terbaru."
                    required style="width:100%; resize:vertical;"></textarea>
            </div>

            <div style="margin-bottom:16px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Dokumen Pengganti / Pembaharuan (opsional)</label>
                <select name="pengganti_document_id" class="form-control" style="width:100%;">
                    <option value="">— Tidak ada dokumen pengganti —</option>
                    @foreach($dokumenPengganti as $dp)
                        <option value="{{ $dp->id }}">{{ $dp->nomor_surat }} — {{ Str::limit($dp->judul, 50) }}</option>
                    @endforeach
                </select>
                <small style="color:#64748b; font-size:0.75rem;">Pilih jika dokumen ini ditarik karena telah ada versi/naskah yang lebih baru.</small>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalUnpublish')">Batal</button>
                <button type="submit" class="btn btn-danger">Konfirmasi Penarikan</button>
            </div>
        </form>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════ --}}
{{-- MODAL: Publikasi Ulang (Republish)                     --}}
{{-- ═══════════════════════════════════════════════════════ --}}
<div id="modalRepublish" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:550px;">
        <h3 style="margin-top:0; font-size:1.25rem; font-weight:700; color:var(--text-primary);">🔄 Publikasi Ulang Naskah Dinas</h3>
        <p id="repDocTitle" style="font-size:0.875rem; color:#64748b; margin-bottom:4px;"></p>
        <p id="repDocAlasan" style="font-size:0.8rem; color:#92400e; margin-bottom:16px;"></p>

        <form id="formRepublish" method="POST">
            @csrf
            <div style="margin-bottom:16px;">
                <label style="font-size:0.875rem; font-weight:600; display:block; margin-bottom:8px;">Tingkat Visibilitas *</label>

                <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.875rem; cursor:pointer;">
                    <input type="radio" name="visibility_scope" value="internal" id="rep_scope_internal" onchange="toggleRepUnitList()">
                    <strong>🌐 Publik Internal RS</strong>
                </label>

                <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.875rem; cursor:pointer;">
                    <input type="radio" name="visibility_scope" value="unit" id="rep_scope_unit" onchange="toggleRepUnitList()">
                    <strong>🏢 Unit Terkait</strong>
                </label>

                <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.875rem; cursor:pointer;">
                    <input type="radio" name="visibility_scope" value="terbatas" id="rep_scope_terbatas" onchange="toggleRepUnitList()">
                    <strong>🔒 Terbatas / Rahasia</strong>
                </label>
            </div>

            <div id="repUnitListSection" style="display:none; margin-bottom:16px; padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:6px;">Pilih Unit Kerja Tujuan Distribusi:</label>
                <div style="max-height:150px; overflow-y:auto; display:flex; flex-direction:column; gap:6px;">
                    @foreach($units as $u)
                        <label style="font-size:0.8rem; display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" name="unit_ids[]" value="{{ $u->id }}">
                            {{ $u->nama }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalRepublish')">Batal</button>
                <button type="submit" class="btn btn-success">Publikasikan Ulang</button>
            </div>
        </form>
    </div>
</div>

<style>
    .modal-overlay {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5); z-index: 1000;
        align-items: center; justify-content: center;
    }
    .modal-content {
        background: #fff; width: 90%; border-radius: 12px;
        padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
        max-height: 90vh; overflow-y: auto;
    }
</style>

<script>
// ====== Publikasi Modal ======
function openModalPublikasi(doc) {
    document.getElementById('formPublikasi').action = '/publikasi/' + doc.id + '/publikasi';
    document.getElementById('modalDocTitle').innerText = (doc.nomor_surat || 'Draft') + ' — ' + doc.judul;

    if (doc.is_rahasia) {
        document.getElementById('scope_terbatas').checked = true;
    } else {
        document.getElementById('scope_internal').checked = true;
    }

    toggleUnitList();
    document.getElementById('modalPublikasi').style.display = 'flex';
}

function toggleUnitList() {
    document.getElementById('unitListSection').style.display =
        document.getElementById('scope_unit').checked ? 'block' : 'none';
}

// ====== Unpublish Modal ======
function openModalUnpublish(doc) {
    document.getElementById('formUnpublish').action = '/publikasi/' + doc.id + '/unpublish';
    document.getElementById('unpubDocTitle').innerText = doc.nomor_surat + ' — ' + doc.judul;
    document.getElementById('modalUnpublish').style.display = 'flex';
}

// ====== Republish Modal ======
function openModalRepublish(doc) {
    document.getElementById('formRepublish').action = '/publikasi/' + doc.id + '/republish';
    document.getElementById('repDocTitle').innerText = (doc.nomor_surat || 'Draft') + ' — ' + doc.judul;
    document.getElementById('repDocAlasan').innerText = doc.alasan_penarikan
        ? '📌 Alasan penarikan sebelumnya: ' + doc.alasan_penarikan
        : '';

    var scope = doc.visibility_scope || 'internal';
    document.getElementById('rep_scope_' + scope).checked = true;
    toggleRepUnitList();
    document.getElementById('modalRepublish').style.display = 'flex';
}

function toggleRepUnitList() {
    document.getElementById('repUnitListSection').style.display =
        document.getElementById('rep_scope_unit').checked ? 'block' : 'none';
}

// ====== Close any modal ======
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
</script>

@endsection
