@extends('layouts.app')

@section('title', 'Master Pengguna & Akun')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('admin.index') }}" style="color:inherit; text-decoration:none;">Admin</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Pengguna & Akun</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; justify-content:space-between; align-items:center;">
    <div>
        <h1 class="page-title">Master Pengguna & Akun</h1>
        <p class="page-subtitle">Kelola akun staf, verifikator, pejabat penandatangan, dan hak akses (roles)</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('modalAddUser').style.display='flex'">
        + Tambah Pengguna
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
    <form method="GET" action="{{ route('admin.users.index') }}" style="display:flex; gap:12px; flex-wrap:wrap;">
        <input type="text" name="search" class="form-control" placeholder="Cari nama, email, NIP, atau jabatan..." value="{{ request('search') }}" style="flex:1.5; min-width:200px;">
        
        <select name="unit_id" class="form-control" style="flex:1; min-width:160px;">
            <option value="">-- Semua Unit Kerja --</option>
            @foreach($units as $u)
                <option value="{{ $u->id }}" {{ request('unit_id') == $u->id ? 'selected' : '' }}>{{ $u->nama }}</option>
            @endforeach
        </select>

        <select name="role" class="form-control" style="flex:1; min-width:160px;">
            <option value="">-- Semua Role --</option>
            @foreach($roles as $r)
                <option value="{{ $r->name }}" {{ request('role') == $r->name ? 'selected' : '' }}>{{ $r->name }}</option>
            @endforeach
        </select>

        <button type="submit" class="btn btn-primary">Filter</button>
        @if(request()->hasAny(['search', 'unit_id', 'role']))
            <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">Reset</a>
        @endif
    </form>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nama & Email</th>
                    <th>NIP / NIK</th>
                    <th>Jabatan & Unit</th>
                    <th>Hak Akses (Role)</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                <tr>
                    <td>
                        <strong style="color:var(--text-primary);">{{ $user->name }}</strong>
                        <div style="font-size:0.75rem; color:#64748b;">{{ $user->email }}</div>
                    </td>
                    <td>{{ $user->nip ?? '-' }}</td>
                    <td>
                        <div style="font-weight:600;">{{ $user->jabatan ?? '-' }}</div>
                        <div style="font-size:0.75rem; color:var(--brand-600);">{{ $user->unit?->nama ?? '-' }}</div>
                    </td>
                    <td>
                        @foreach($user->roles as $role)
                            <span class="badge badge-indigo">{{ $role->name }}</span>
                        @endforeach
                    </td>
                    <td>
                        @if($user->is_active)
                            <span class="badge badge-green">Aktif</span>
                        @else
                            <span class="badge badge-red">Non-Aktif</span>
                        @endif
                    </td>
                    <td>
                        <button class="btn btn-secondary btn-sm" onclick="editUser({{ json_encode($user) }}, {{ json_encode($user->roles->pluck('name')) }})">Edit</button>
                        <form action="{{ route('admin.users.destroy', $user) }}" method="POST" style="display:inline;" data-confirm="Hapus akun pengguna ini?">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">Belum ada data pengguna.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:15px;">{{ $users->withQueryString()->links() }}</div>
</div>

{{-- Modal Add User --}}
<div id="modalAddUser" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; width:100%; max-width:550px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); max-height:90vh; overflow-y:auto;">
        <h3 style="margin-top:0; font-size:1.2rem; font-weight:700;">Tambah Pengguna Baru</h3>
        <form method="POST" action="{{ route('admin.users.store') }}">
            @csrf
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Nama Lengkap *</label>
                <input type="text" name="name" class="form-control" placeholder="Contoh: Dr. Budi Santoso, Sp.B" required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Email *</label>
                <input type="email" name="email" class="form-control" placeholder="budi@rs.id" required>
            </div>
            <div style="display:flex; gap:12px; margin-bottom:12px;">
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Password *</label>
                    <input type="password" name="password" class="form-control" placeholder="Minimal 6 karakter" required>
                </div>
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">NIP / NIK</label>
                    <input type="text" name="nip" class="form-control" placeholder="19850101...">
                </div>
            </div>
            <div style="display:flex; gap:12px; margin-bottom:12px;">
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Jabatan</label>
                    <input type="text" name="jabatan" class="form-control" placeholder="Direktur / Kepala Instalasi / Ketua Komite">
                </div>
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Unit Kerja / Instalasi</label>
                    <select name="unit_id" class="form-control">
                        <option value="">-- Pilih Unit Kerja --</option>
                        @foreach($units as $u)
                            <option value="{{ $u->id }}">{{ $u->nama }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Hak Akses (Role)</label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; background:#f8fafc; padding:10px; border-radius:6px;">
                    @foreach($roles as $r)
                        <label style="font-size:0.8rem; display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" name="roles[]" value="{{ $r->name }}"> {{ $r->name }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; font-weight:600; cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1" checked> Aktifkan Akun Pengguna
                </label>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalAddUser').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Pengguna</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Edit User --}}
<div id="modalEditUser" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; width:100%; max-width:550px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); max-height:90vh; overflow-y:auto;">
        <h3 style="margin-top:0; font-size:1.2rem; font-weight:700;">Edit Akun Pengguna</h3>
        <form id="formEditUser" method="POST">
            @csrf
            @method('PUT')
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Nama Lengkap *</label>
                <input type="text" id="edit_name" name="name" class="form-control" required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Email *</label>
                <input type="email" id="edit_email" name="email" class="form-control" required>
            </div>
            <div style="display:flex; gap:12px; margin-bottom:12px;">
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Password Baru (Kosongkan jika tidak diubah)</label>
                    <input type="password" name="password" class="form-control" placeholder="••••••••">
                </div>
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">NIP / NIK</label>
                    <input type="text" id="edit_nip" name="nip" class="form-control">
                </div>
            </div>
            <div style="display:flex; gap:12px; margin-bottom:12px;">
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Jabatan</label>
                    <input type="text" id="edit_jabatan" name="jabatan" class="form-control">
                </div>
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Unit Kerja / Instalasi</label>
                    <select id="edit_unit_id" name="unit_id" class="form-control">
                        <option value="">-- Pilih Unit Kerja --</option>
                        @foreach($units as $u)
                            <option value="{{ $u->id }}">{{ $u->nama }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Hak Akses (Role)</label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; background:#f8fafc; padding:10px; border-radius:6px;">
                    @foreach($roles as $r)
                        <label style="font-size:0.8rem; display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" name="roles[]" class="edit-role-checkbox" value="{{ $r->name }}"> {{ $r->name }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; font-weight:600; cursor:pointer;">
                    <input type="checkbox" id="edit_is_active" name="is_active" value="1"> Aktifkan Akun Pengguna
                </label>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalEditUser').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function editUser(user, userRoles) {
    document.getElementById('formEditUser').action = '/admin/users/' + user.id;
    document.getElementById('edit_name').value = user.name;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_nip').value = user.nip || '';
    document.getElementById('edit_jabatan').value = user.jabatan || '';
    document.getElementById('edit_unit_id').value = user.unit_id || '';
    document.getElementById('edit_is_active').checked = !!user.is_active;

    document.querySelectorAll('.edit-role-checkbox').forEach(cb => {
        cb.checked = userRoles.includes(cb.value);
    });

    document.getElementById('modalEditUser').style.display = 'flex';
}
</script>

@endsection
