@extends('layouts.app')

@section('title', 'Master Jenis Naskah & Klasifikasi')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('admin.index') }}" style="color:inherit; text-decoration:none;">Admin</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Jenis Naskah & Klasifikasi</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; justify-content:space-between; align-items:center;">
    <div>
        <h1 class="page-title">Master Jenis Naskah & Klasifikasi Dokumen</h1>
        <p class="page-subtitle">Kelola kategori naskah dinas (SK, SOP, Pedoman, Nota Dinas) & rumus format penomoran otomatis</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('modalAddType').style.display='flex'">
        + Tambah Jenis Naskah
    </button>
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

{{-- Filter & Search --}}
<div class="card" style="margin-bottom: var(--space-6); padding: var(--space-4); background: #f8fafc;">
    <form method="GET" action="{{ route('admin.jenis-naskah.index') }}" style="display:flex; gap:12px;">
        <input type="text" name="search" class="form-control" placeholder="Cari nama, kode, atau singkatan naskah..." value="{{ request('search') }}" style="flex:1;">
        <button type="submit" class="btn btn-primary">Cari</button>
        @if(request('search'))
            <a href="{{ route('admin.jenis-naskah.index') }}" class="btn btn-secondary">Reset</a>
        @endif
    </form>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Jenis Naskah</th>
                    <th>Singkatan</th>
                    <th>Formula Format Nomor</th>
                    <th>Level Verifikasi</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($documentTypes as $dt)
                <tr>
                    <td><strong style="color:var(--brand-600);">{{ $dt->kode }}</strong></td>
                    <td style="font-weight:600; color:var(--text-primary);">{{ $dt->nama }}</td>
                    <td><span class="badge badge-purple">{{ $dt->singkatan }}</span></td>
                    <td><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:0.8rem; color:#0f172a;">{{ $dt->format_nomor }}</code></td>
                    <td><span class="badge badge-indigo">{{ $dt->level_verifikasi }} Tahap</span></td>
                    <td>
                        @if($dt->is_active)
                            <span class="badge badge-green">Aktif</span>
                        @else
                            <span class="badge badge-red">Non-Aktif</span>
                        @endif
                    </td>
                    <td>
                        <button class="btn btn-secondary btn-sm" onclick="editType({{ json_encode($dt) }})">Edit</button>
                        <form action="{{ route('admin.jenis-naskah.destroy', $dt) }}" method="POST" style="display:inline;" onsubmit="return confirm('Yakin ingin menghapus jenis naskah ini?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align:center; padding:30px; color:#94a3b8;">Belum ada data jenis naskah.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:15px;">{{ $documentTypes->withQueryString()->links() }}</div>
</div>

{{-- Modal Add Type --}}
<div id="modalAddType" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; width:100%; max-width:550px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); max-height:90vh; overflow-y:auto;">
        <h3 style="margin-top:0; font-size:1.2rem; font-weight:700;">Tambah Jenis Naskah / Klasifikasi Baru</h3>
        <form method="POST" action="{{ route('admin.jenis-naskah.store') }}">
            @csrf
            <div style="display:flex; gap:12px; margin-bottom:12px;">
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Kode Klasifikasi *</label>
                    <input type="text" name="kode" class="form-control" placeholder="Contoh: SK" required>
                </div>
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Singkatan *</label>
                    <input type="text" name="singkatan" class="form-control" placeholder="Contoh: SK-DIR" required>
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Nama Jenis Naskah *</label>
                <input type="text" name="nama" class="form-control" placeholder="Contoh: Surat Keputusan Direktur" required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Formula Format Penomoran Otomatis *</label>
                <input type="text" name="format_nomor" class="form-control" value="{urut}/{kode}/{unit}/{rs}/{bulan_romawi}/{tahun}" required>
                <div style="font-size:0.75rem; color:#64748b; margin-top:4px;">
                    Variabel tersedia: <code>{urut}</code>, <code>{kode}</code>, <code>{induk}</code> (Kode Induk/Bidang), <code>{unit}</code> (Kode Bagian/Instalasi), <code>{rs}</code>, <code>{bulan_romawi}</code>, <code>{bulan}</code>, <code>{tahun}</code>
                </div>
            </div>
            <div style="display:flex; gap:12px; margin-bottom:12px;">
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Jumlah Level Verifikasi *</label>
                    <select name="level_verifikasi" class="form-control" required>
                        <option value="1">1 Tahap (Langsung Verifikator Unit)</option>
                        <option value="2">2 Tahap (Verifikator Unit $\rightarrow$ Kabid/Wadir)</option>
                        <option value="3">3 Tahap (Verifikator Unit $\rightarrow$ Kabid $\rightarrow$ Wadir)</option>
                    </select>
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Deskripsi Naskah</label>
                <textarea name="deskripsi" class="form-control" rows="2" placeholder="Penjelasan singkat mengenai naskah dinas ini..."></textarea>
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; font-weight:600; cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1" checked> Aktifkan Jenis Naskah
                </label>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalAddType').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Jenis Naskah</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Edit Type --}}
<div id="modalEditType" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; width:100%; max-width:550px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); max-height:90vh; overflow-y:auto;">
        <h3 style="margin-top:0; font-size:1.2rem; font-weight:700;">Edit Jenis Naskah</h3>
        <form id="formEditType" method="POST">
            @csrf
            @method('PUT')
            <div style="display:flex; gap:12px; margin-bottom:12px;">
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Kode Klasifikasi *</label>
                    <input type="text" id="edit_type_kode" name="kode" class="form-control" required>
                </div>
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Singkatan *</label>
                    <input type="text" id="edit_type_singkatan" name="singkatan" class="form-control" required>
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Nama Jenis Naskah *</label>
                <input type="text" id="edit_type_nama" name="nama" class="form-control" required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Formula Format Penomoran Otomatis *</label>
                <input type="text" id="edit_type_format_nomor" name="format_nomor" class="form-control" required>
            </div>
            <div style="display:flex; gap:12px; margin-bottom:12px;">
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Jumlah Level Verifikasi *</label>
                    <select id="edit_type_level_verifikasi" name="level_verifikasi" class="form-control" required>
                        <option value="1">1 Tahap</option>
                        <option value="2">2 Tahap</option>
                        <option value="3">3 Tahap</option>
                    </select>
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Deskripsi Naskah</label>
                <textarea id="edit_type_deskripsi" name="deskripsi" class="form-control" rows="2"></textarea>
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; font-weight:600; cursor:pointer;">
                    <input type="checkbox" id="edit_type_is_active" name="is_active" value="1"> Aktifkan Jenis Naskah
                </label>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalEditType').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function editType(dt) {
    document.getElementById('formEditType').action = '/admin/jenis-naskah/' + dt.id;
    document.getElementById('edit_type_kode').value = dt.kode;
    document.getElementById('edit_type_singkatan').value = dt.singkatan;
    document.getElementById('edit_type_nama').value = dt.nama;
    document.getElementById('edit_type_format_nomor').value = dt.format_nomor;
    document.getElementById('edit_type_level_verifikasi').value = dt.level_verifikasi;
    document.getElementById('edit_type_deskripsi').value = dt.deskripsi || '';
    document.getElementById('edit_type_is_active').checked = !!dt.is_active;

    document.getElementById('modalEditType').style.display = 'flex';
}
</script>

@endsection
