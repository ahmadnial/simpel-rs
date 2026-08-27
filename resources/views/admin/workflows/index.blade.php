@extends('layouts.app')

@section('title', 'Master Template Workflow Verifikasi')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('admin.index') }}" style="color:inherit; text-decoration:none;">Admin</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Workflow Verifikasi</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; justify-content:space-between; align-items:center;">
    <div>
        <h1 class="page-title">Master Template Workflow & Alur Verifikasi</h1>
        <p class="page-subtitle">Atur skema rantai persetujuan (approval pipeline) berdasarkan jenis dokumen & unit pengusul</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('modalAddWorkflow').style.display='flex'">
        + Tambah Template Workflow
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
    <form method="GET" action="{{ route('admin.workflows.index') }}" style="display:flex; gap:12px;">
        <input type="text" name="search" class="form-control" placeholder="Cari nama workflow..." value="{{ request('search') }}" style="flex:1;">
        <button type="submit" class="btn btn-primary">Cari</button>
        @if(request('search'))
            <a href="{{ route('admin.workflows.index') }}" class="btn btn-secondary">Reset</a>
        @endif
    </form>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nama Workflow</th>
                    <th>Jenis Naskah</th>
                    <th>Spesifik Unit</th>
                    <th>Jumlah Tahap (Steps)</th>
                    <th>Default</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($workflows as $wf)
                <tr>
                    <td style="font-weight:600; color:var(--text-primary);">{{ $wf->nama }}</td>
                    <td><span class="badge badge-purple">{{ $wf->documentType?->nama ?? 'Semua Jenis' }}</span></td>
                    <td>{{ $wf->unit?->nama ?? 'Semua Unit (Global)' }}</td>
                    <td><span class="badge badge-indigo">{{ $wf->steps->count() }} Tahap</span></td>
                    <td>
                        @if($wf->is_default)
                            <span class="badge badge-green">Default</span>
                        @else
                            <span class="badge badge-yellow">Opsional</span>
                        @endif
                    </td>
                    <td>
                        @if($wf->is_active)
                            <span class="badge badge-green">Aktif</span>
                        @else
                            <span class="badge badge-red">Non-Aktif</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('admin.workflows.steps', $wf) }}" class="btn btn-primary btn-sm">Kelola Tahapan</a>
                        <button class="btn btn-secondary btn-sm" onclick="editWorkflow({{ json_encode($wf) }})">Edit</button>
                        <form action="{{ route('admin.workflows.destroy', $wf) }}" method="POST" style="display:inline;" onsubmit="return confirm('Yakin ingin menghapus template workflow ini?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align:center; padding:30px; color:#94a3b8;">Belum ada data template workflow.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:15px;">{{ $workflows->withQueryString()->links() }}</div>
</div>

{{-- Modal Add Workflow --}}
<div id="modalAddWorkflow" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; width:100%; max-width:550px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); max-height:90vh; overflow-y:auto;">
        <h3 style="margin-top:0; font-size:1.2rem; font-weight:700;">Tambah Template Workflow Baru</h3>
        <form method="POST" action="{{ route('admin.workflows.store') }}">
            @csrf
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Nama Template Workflow *</label>
                <input type="text" name="nama" class="form-control" placeholder="Contoh: Workflow Standar Surat Keputusan" required>
            </div>
            <div style="display:flex; gap:12px; margin-bottom:12px;">
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Jenis Naskah *</label>
                    <select name="document_type_id" class="form-control" required>
                        @foreach($documentTypes as $dt)
                            <option value="{{ $dt->id }}">{{ $dt->nama }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Spesifik Unit Kerja (Optional)</label>
                    <select name="unit_id" class="form-control">
                        <option value="">-- Berlaku Semua Unit --</option>
                        @foreach($units as $u)
                            <option value="{{ $u->id }}">{{ $u->nama }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Deskripsi Workflow</label>
                <textarea name="deskripsi" class="form-control" rows="2" placeholder="Penjelasan alur verifikasi..."></textarea>
            </div>
            <div style="display:flex; gap:16px; margin-bottom:16px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; font-weight:600; cursor:pointer;">
                    <input type="checkbox" name="is_default" value="1" checked> Jadikan Template Utama
                </label>
                <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; font-weight:600; cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1" checked> Aktifkan Workflow
                </label>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalAddWorkflow').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Workflow</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Edit Workflow --}}
<div id="modalEditWorkflow" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; width:100%; max-width:550px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); max-height:90vh; overflow-y:auto;">
        <h3 style="margin-top:0; font-size:1.2rem; font-weight:700;">Edit Template Workflow</h3>
        <form id="formEditWorkflow" method="POST">
            @csrf
            @method('PUT')
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Nama Template Workflow *</label>
                <input type="text" id="edit_wf_nama" name="nama" class="form-control" required>
            </div>
            <div style="display:flex; gap:12px; margin-bottom:12px;">
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Jenis Naskah *</label>
                    <select id="edit_wf_document_type_id" name="document_type_id" class="form-control" required>
                        @foreach($documentTypes as $dt)
                            <option value="{{ $dt->id }}">{{ $dt->nama }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Spesifik Unit Kerja (Optional)</label>
                    <select id="edit_wf_unit_id" name="unit_id" class="form-control">
                        <option value="">-- Berlaku Semua Unit --</option>
                        @foreach($units as $u)
                            <option value="{{ $u->id }}">{{ $u->nama }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Deskripsi Workflow</label>
                <textarea id="edit_wf_deskripsi" name="deskripsi" class="form-control" rows="2"></textarea>
            </div>
            <div style="display:flex; gap:16px; margin-bottom:16px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; font-weight:600; cursor:pointer;">
                    <input type="checkbox" id="edit_wf_is_default" name="is_default" value="1"> Jadikan Template Utama
                </label>
                <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; font-weight:600; cursor:pointer;">
                    <input type="checkbox" id="edit_wf_is_active" name="is_active" value="1"> Aktifkan Workflow
                </label>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalEditWorkflow').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function editWorkflow(wf) {
    document.getElementById('formEditWorkflow').action = '/admin/workflows/' + wf.id;
    document.getElementById('edit_wf_nama').value = wf.nama;
    document.getElementById('edit_wf_document_type_id').value = wf.document_type_id;
    document.getElementById('edit_wf_unit_id').value = wf.unit_id || '';
    document.getElementById('edit_wf_deskripsi').value = wf.deskripsi || '';
    document.getElementById('edit_wf_is_default').checked = !!wf.is_default;
    document.getElementById('edit_wf_is_active').checked = !!wf.is_active;

    document.getElementById('modalEditWorkflow').style.display = 'flex';
}
</script>

@endsection
