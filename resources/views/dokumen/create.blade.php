@extends('layouts.app')

@section('title', 'Buat Dokumen Baru')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('dokumen.index') }}" style="color:var(--text-muted)">Dokumen Saya</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Buat Baru</span>
@endsection

@section('content')

<div class="page-header" style="max-width: 800px; margin: 0 auto var(--space-8)">
    <h1 class="page-title">Buat Naskah Dinas Baru</h1>
    <p class="page-subtitle">Unggah draft .docx/.pdf dan pilih alur verifikasi yang sesuai</p>
</div>

<div class="card" style="max-width: 800px; margin: 0 auto">
    <form method="POST" action="{{ route('dokumen.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="form-group">
            <label for="document_type_id" class="form-label">Jenis Naskah Dinas <span style="color:#ef4444">*</span></label>
            <select name="document_type_id" id="document_type_id" class="form-control" required>
                <option value="">-- Pilih Jenis Naskah --</option>
                @foreach($documentTypes as $type)
                    <option value="{{ $type->id }}" {{ old('document_type_id') == $type->id ? 'selected' : '' }}>
                        [{{ $type->singkatan }}] {{ $type->nama }} &mdash; (Format: {{ $type->format_nomor }})
                    </option>
                @endforeach
            </select>
            @error('document_type_id') <div class="form-error">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <label for="judul" class="form-label">Judul Dokumen <span style="color:#ef4444">*</span></label>
            <input type="text" name="judul" id="judul" class="form-control" placeholder="mis: SK Kebijakan Pelayanan Medis Tahun 2026" value="{{ old('judul') }}" required>
            @error('judul') <div class="form-error">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <label for="perihal" class="form-label">Perihal / Hal</label>
            <input type="text" name="perihal" id="perihal" class="form-control" placeholder="mis: Pengangkatan Komite Medik" value="{{ old('perihal') }}">
        </div>

        <div class="form-group">
            <label for="keterangan" class="form-label">Keterangan Catatan Tambahan</label>
            <textarea name="keterangan" id="keterangan" class="form-control" rows="3" placeholder="Catatan internal pengajuan draft...">{{ old('keterangan') }}</textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Unggah File Dokumen (.docx / .pdf) <span style="color:#ef4444">*</span></label>
            <div class="upload-zone" onclick="document.getElementById('file_dokumen').click()" id="upload-box">
                <div class="upload-zone-icon">📄</div>
                <div class="upload-zone-text" id="file-label">Klik atau drag & drop file Word (.docx) di sini</div>
                <div class="upload-zone-hint">Maksimal ukuran file 10MB</div>
                <input type="file" name="file_dokumen" id="file_dokumen" style="display:none" accept=".docx,.doc,.pdf" required onchange="updateFileLabel(this)">
            </div>
        <div class="form-group" id="preview-wrapper" style="display:none; margin-top: var(--space-4)">
            <label class="form-label">Pratinjau Dokumen (.docx):</label>
            <div class="docx-paper-wrapper">
                <div id="live-docx-container" class="docx-render-target"></div>
            </div>
        </div>
        @error('file_dokumen') <div class="form-error">{{ $message }}</div> @enderror
    </div>

        <hr style="border:none; border-top:1px solid var(--border-subtle); margin: var(--space-6) 0">

        <div class="form-group">
            <label for="verifikator_id" class="form-label">Pilih Verifikator Pertama (Opsional &mdash; Langsung Ajukan)</label>
            <select name="verifikator_id" id="verifikator_id" class="form-control">
                <option value="">-- Simpan sebagai Draft saja --</option>
                @foreach($verifikators as $v)
                    <option value="{{ $v->id }}">
                        {{ $v->name }} ({{ $v->jabatan ?? 'Verifikator' }}) &mdash; {{ $v->unit?->nama }}
                    </option>
                @endforeach
            </select>
            <small style="color:var(--text-muted); font-size:0.78rem; display:block; margin-top:4px">
                Jika dipilihi, dokumen akan langsung dikirim ke antrian verifikator tersebut.
            </small>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="is_rahasia" value="1" {{ old('is_rahasia') ? 'checked' : '' }}>
                Dokumen bersifat Rahasia / Terbatas (Hanya unit terkait yang dapat membaca)
            </label>
        </div>

        <div style="display:flex; justify-content:flex-end; gap: var(--space-3); margin-top: var(--space-6)">
            <a href="{{ route('dokumen.index') }}" class="btn btn-secondary">Batal</a>
            <button type="submit" class="btn btn-primary">Simpan / Ajukan Dokumen</button>
        </div>
    </form>
</div>

<script>
    function updateFileLabel(input) {
        if (input.files && input.files[0]) {
            const file = input.files[0];
            document.getElementById('file-label').innerHTML = '<strong>' + file.name + '</strong> (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
            document.getElementById('upload-box').style.borderColor = 'var(--brand-500)';
            document.getElementById('upload-box').style.background = 'rgba(99,102,241,0.1)';

            if (file.name.endsWith('.docx')) {
                document.getElementById('preview-wrapper').style.display = 'block';
                const container = document.getElementById('live-docx-container');
                container.innerHTML = '<div style="text-align:center; padding:2rem; color:#666">Memuat pratinjau naskah...</div>';
                
                if (typeof docx !== 'undefined') {
                    docx.renderAsync(file, container, null, {
                        inWrapper: false,
                        ignoreWidth: true,
                        breakPages: true
                    }).catch(err => {
                        container.innerHTML = '<div style="color:#ef4444; padding:1rem">Pratinjau tidak dapat ditampilkan, namun file tetap valid untuk diunggah.</div>';
                    });
                }
            }
        }
    }
</script>

@endsection
