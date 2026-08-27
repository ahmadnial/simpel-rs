@extends('layouts.app')

@section('title', 'Kelola Tahapan Workflow')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('admin.index') }}" style="color:inherit; text-decoration:none;">Admin</a>
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('admin.workflows.index') }}" style="color:inherit; text-decoration:none;">Workflow Verifikasi</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Tahapan</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; justify-content:space-between; align-items:center;">
    <div>
        <h1 class="page-title">Tahapan Workflow: {{ $workflow->nama }}</h1>
        <p class="page-subtitle">Atur skema rantai persetujuan untuk template ini.</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('modalAddStep').style.display='flex'">
        + Tambah Tahapan
    </button>
</div>

@if(session('success'))
    <div style="padding: 12px 16px; background: #f0fdf4; border: 1px solid #86efac; color: #166534; border-radius: 8px; margin-bottom: 20px;">
        {{ session('success') }}
    </div>
@endif

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Urutan</th>
                    <th>Nama Tahapan</th>
                    <th>Tipe</th>
                    <th>Mode</th>
                    <th>Verifier (Pool/Role)</th>
                    <th>SLA (Hari Kerja)</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($workflow->steps->sortBy('urutan') as $step)
                <tr>
                    <td><span class="badge badge-gray">{{ $step->urutan }}</span></td>
                    <td style="font-weight:600; color:var(--text-primary);">{{ $step->nama_tahap }}</td>
                    <td>
                        <span class="badge {{ $step->tipe == 'verifikasi' ? 'badge-yellow' : 'badge-purple' }}">
                            {{ ucfirst($step->tipe) }}
                        </span>
                    </td>
                    <td>
                        @if($step->mode_verifikasi == 'parallel')
                            <span class="badge badge-indigo">Parallel (Min: {{ $step->min_approval }})</span>
                        @else
                            <span class="badge badge-gray">Serial</span>
                        @endif
                    </td>
                    <td>
                        @if($step->mode_verifikasi == 'parallel')
                            <div style="font-size:0.8rem; color:var(--text-muted)">
                                {{ $step->verifierPool->count() }} Anggota Pool
                            </div>
                        @else
                            @if($step->role_nama)
                                <span class="badge badge-gray">{{ $step->role_nama }}</span>
                            @else
                                <span style="font-size:0.8rem; color:var(--text-muted)">Ditentukan saat pengajuan</span>
                            @endif
                        @endif
                    </td>
                    <td>{{ $step->sla_hari_kerja }} Hari</td>
                    <td>
                        <form action="{{ route('admin.workflows.steps.destroy', $step) }}" method="POST" style="display:inline;" onsubmit="return confirm('Yakin ingin menghapus tahapan ini?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align:center; padding:30px; color:#94a3b8;">Belum ada data tahapan.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Modal Add Step --}}
<div id="modalAddStep" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; width:100%; max-width:650px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); max-height:90vh; overflow-y:auto;">
        <h3 style="margin-top:0; font-size:1.2rem; font-weight:700;">Tambah Tahapan Workflow</h3>
        <form method="POST" action="{{ route('admin.workflows.steps.store', $workflow) }}">
            @csrf
            
            <div style="display:flex; gap:12px; margin-bottom:12px;">
                <div style="flex:2;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Nama Tahapan *</label>
                    <input type="text" name="nama_tahap" class="form-control" placeholder="Contoh: Verifikasi Kepala Instalasi / Ketua Komite" required>
                </div>
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Urutan *</label>
                    <input type="number" name="urutan" class="form-control" value="{{ $workflow->steps->count() + 1 }}" required>
                </div>
            </div>

            <div style="display:flex; gap:12px; margin-bottom:12px;">
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Tipe *</label>
                    <select name="tipe" class="form-control" required>
                        <option value="verifikasi">Verifikasi</option>
                        <option value="penandatangan">Penandatangan</option>
                    </select>
                </div>
                <div style="flex:1;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">SLA (Hari) *</label>
                    <input type="number" name="sla_hari_kerja" class="form-control" value="2" required>
                </div>
            </div>

            <hr style="border:0; border-top:1px solid #e2e8f0; margin:16px 0;">
            
            <h4 style="margin:0 0 12px 0; font-size:1rem; font-weight:600;">Pengaturan Verifikator</h4>

            <div style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Mode Verifikasi *</label>
                <select name="mode_verifikasi" id="mode_verifikasi" class="form-control" onchange="toggleMode()" required>
                    <option value="serial">Serial / Tunggal</option>
                    <option value="parallel">Parallel / Multi-Approval Quorum</option>
                </select>
            </div>

            {{-- Mode Serial Settings --}}
            <div id="settings_serial" style="margin-bottom:12px;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Role Spesifik (Serial)</label>
                <select name="role_nama" class="form-control">
                    <option value="">-- Ditentukan oleh Pengusul --</option>
                    @foreach($roles as $r)
                        <option value="{{ $r->name }}">{{ $r->name }}</option>
                    @endforeach
                </select>
                <small style="color:var(--text-muted); display:block; margin-top:4px;">Jika dikosongkan, pengusul harus memilih verifikator secara manual.</small>
            </div>

            {{-- Mode Parallel Settings --}}
            <div id="settings_parallel" style="display:none; padding:12px; background:var(--bg-body); border:1px solid var(--border-light); border-radius:8px; margin-bottom:12px;">
                <div style="margin-bottom:12px;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Minimal Persetujuan (Quorum) *</label>
                    <input type="number" name="min_approval" id="min_approval" class="form-control" value="1" min="1">
                    <small style="color:var(--text-muted); display:block; margin-top:4px;">Berapa banyak orang yang harus menyetujui dari pool verifikator agar lolos tahapan ini.</small>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Pilih User untuk Pool Parallel</label>
                    <div style="max-height:100px; overflow-y:auto; border:1px solid var(--border-light); padding:8px; border-radius:4px; background:#fff;">
                        @foreach($users as $u)
                            <label style="display:flex; align-items:center; gap:8px; margin-bottom:4px; font-size:0.85rem;">
                                <input type="checkbox" name="verifier_users[]" value="{{ $u->id }}"> {{ $u->name }} ({{ $u->unit->nama ?? '-' }})
                            </label>
                        @endforeach
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Pilih Role untuk Pool Parallel</label>
                    <div style="max-height:100px; overflow-y:auto; border:1px solid var(--border-light); padding:8px; border-radius:4px; background:#fff;">
                        @foreach($roles as $r)
                            <label style="display:flex; align-items:center; gap:8px; margin-bottom:4px; font-size:0.85rem;">
                                <input type="checkbox" name="verifier_roles[]" value="{{ $r->name }}"> Role: {{ $r->name }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalAddStep').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Tahapan</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleMode() {
        const mode = document.getElementById('mode_verifikasi').value;
        if(mode === 'parallel') {
            document.getElementById('settings_parallel').style.display = 'block';
            document.getElementById('settings_serial').style.display = 'none';
        } else {
            document.getElementById('settings_parallel').style.display = 'none';
            document.getElementById('settings_serial').style.display = 'block';
        }
    }
</script>

@endsection
