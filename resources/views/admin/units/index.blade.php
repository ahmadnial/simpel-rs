@extends('layouts.app')

@section('title', 'Master Unit Kerja & Instalasi')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('admin.index') }}" style="color:inherit; text-decoration:none;">Admin</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Unit Kerja & Instalasi</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; justify-content:space-between; align-items:center;">
    <div>
        <h1 class="page-title">Master Unit & Instalasi</h1>
        <p class="page-subtitle">Kelola struktur unit, instalasi, tim, komite, dan manajemen rumah sakit</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('modalAddUnit').style.display='flex'">
        + Tambah Unit / Instalasi
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
    <form method="GET" action="{{ route('admin.units.index') }}" style="display:flex; gap:12px;">
        <input type="text" name="search" class="form-control" placeholder="Cari nama, kode, atau singkatan unit..." value="{{ request('search') }}" style="flex:1;">
        <button type="submit" class="btn btn-primary">Cari</button>
        @if(request('search'))
            <a href="{{ route('admin.units.index') }}" class="btn btn-secondary">Reset</a>
        @endif
    </form>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Unit Kerja / Instalasi</th>
                    <th>Singkatan</th>
                    <th>Induk Unit</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($units as $unit)
                <tr>
                    <td><strong style="color:var(--brand-600);">{{ $unit->kode }}</strong></td>
                    <td style="font-weight:600; color:var(--text-primary);">{{ $unit->nama }}</td>
                    <td><span class="badge badge-purple">{{ $unit->singkatan ?? '-' }}</span></td>
                    <td>{{ $unit->parent?->nama ?? '-' }}</td>
                    <td>
                        @if($unit->is_active)
                            <span class="badge badge-green">Aktif</span>
                        @else
                            <span class="badge badge-red">Non-Aktif</span>
                        @endif
                    </td>
                    <td>
                        <button class="btn btn-secondary btn-sm" onclick="editUnit({{ json_encode($unit) }})">Edit</button>
                        <form action="{{ route('admin.units.destroy', $unit) }}" method="POST" style="display:inline;" data-confirm="Hapus unit kerja ini?">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">Belum ada data unit kerja.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:15px;">{{ $units->withQueryString()->links() }}</div>
</div>

{{-- Modal Add Unit --}}
<div id="modalAddUnit" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; width:100%; max-width:500px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <h3 style="margin-top:0; font-size:1.2rem; font-weight:700;">Tambah Unit Kerja Baru</h3>
        <form method="POST" action="{{ route('admin.units.store') }}">
            @csrf
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Kode Unit *</label>
                <input type="text" name="kode" class="form-control" placeholder="Contoh: RS-IRJ" required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Nama Unit / Instalasi *</label>
                <input type="text" name="nama" class="form-control" placeholder="Contoh: Instalasi Rawat Jalan" required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Singkatan</label>
                <input type="text" name="singkatan" class="form-control" placeholder="Contoh: IRJ">
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Induk Unit (Optional)</label>
                <select name="parent_id" class="form-control">
                    <option value="">-- Tanpa Induk (Top Level) --</option>
                    @foreach($parentUnits as $pu)
                        <option value="{{ $pu->id }}">{{ $pu->nama }}</option>
                    @endforeach
                </select>
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; font-weight:600; cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1" checked> Aktifkan Unit Kerja
                </label>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalAddUnit').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Unit</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Edit Unit --}}
<div id="modalEditUnit" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; width:100%; max-width:500px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <h3 style="margin-top:0; font-size:1.2rem; font-weight:700;">Edit Unit Kerja</h3>
        <form id="formEditUnit" method="POST">
            @csrf
            @method('PUT')
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Kode Unit *</label>
                <input type="text" id="edit_kode" name="kode" class="form-control" required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Nama Unit / Instalasi *</label>
                <input type="text" id="edit_nama" name="nama" class="form-control" required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Singkatan</label>
                <input type="text" id="edit_singkatan" name="singkatan" class="form-control">
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Induk Unit (Optional)</label>
                <select id="edit_parent_id" name="parent_id" class="form-control">
                    <option value="">-- Tanpa Induk (Top Level) --</option>
                    @foreach($parentUnits as $pu)
                        <option value="{{ $pu->id }}">{{ $pu->nama }}</option>
                    @endforeach
                </select>
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; font-weight:600; cursor:pointer;">
                    <input type="checkbox" id="edit_is_active" name="is_active" value="1"> Aktifkan Unit Kerja
                </label>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalEditUnit').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function editUnit(unit) {
    document.getElementById('formEditUnit').action = '/admin/units/' + unit.id;
    document.getElementById('edit_kode').value = unit.kode;
    document.getElementById('edit_nama').value = unit.nama;
    document.getElementById('edit_singkatan').value = unit.singkatan || '';
    document.getElementById('edit_parent_id').value = unit.parent_id || '';
    document.getElementById('edit_is_active').checked = !!unit.is_active;
    document.getElementById('modalEditUnit').style.display = 'flex';
}
</script>

@endsection
