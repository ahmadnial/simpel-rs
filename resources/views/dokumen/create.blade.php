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
            <label class="form-label">Setelah Diunggah</label>

            <div class="submit-mode-toggle" id="submit-mode-toggle">
                <label class="submit-mode-option" id="submit-mode-option-ajukan">
                    <input type="radio" name="submit_mode" value="ajukan" id="mode-ajukan" checked>
                    <span class="submit-mode-icon">📤</span>
                    <span class="submit-mode-text">
                        <strong>Ajukan ke Verifikator</strong>
                        <small>Diperiksa lalu ditandatangani secara elektronik sesuai alur</small>
                    </span>
                </label>
                <label class="submit-mode-option" id="submit-mode-option-internal">
                    <input type="radio" name="submit_mode" value="internal" id="mode-internal">
                    <span class="submit-mode-icon">🗂️</span>
                    <span class="submit-mode-text">
                        <strong>Hanya Dokumen Internal</strong>
                        <small>Tersimpan sebagai arsip unit, tanpa verifikasi / TTE</small>
                    </span>
                </label>
            </div>

            <input type="hidden" name="ajukan_langsung" id="ajukan_langsung_hidden" value="0">

            <div id="ajukan-detail" style="margin-top: var(--space-4)">
                <div id="workflow-chain-wrap" style="margin-bottom: var(--space-3)">
                    <div class="workflow-chain" id="workflow-chain"></div>
                </div>
                <div id="verifikator-picker-wrap">
                    <select name="verifikator_ids[]" id="verifikator_ids" class="form-control" multiple data-tomselect data-placeholder="Cari & pilih verifikator...">
                        @foreach($verifikators as $v)
                            <option value="{{ $v->id }}">{{ $v->name }} ({{ $v->jabatan ?? 'Verifikator' }}) &mdash; {{ $v->unit?->nama }}</option>
                        @endforeach
                    </select>
                    <small style="color:var(--text-muted); font-size:0.78rem; display:block; margin-top:4px">Pilih siapa yang akan memeriksa dokumen ini terlebih dahulu.</small>
                </div>
                <p id="not-configured-text" style="display:none; font-size:0.8rem; color:#991b1b; margin:0">Jenis naskah ini belum bisa diajukan ke verifikator. Pilih "Hanya Dokumen Internal", atau hubungi Admin.</p>
            </div>

            <p id="internal-text" style="display:none; font-size:0.8rem; color:var(--text-muted); margin:0">Dokumen hanya tersimpan sebagai arsip unit Anda, tanpa proses verifikasi atau tanda tangan elektronik.</p>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="is_rahasia" value="1" {{ old('is_rahasia') ? 'checked' : '' }}>
                Dokumen Rahasia / Terbatas (Akses terbatas bagi unit/instalasi terkait)
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

    const workflowStepInfo = @json($workflowStepInfo);
    const workflowChainInfo = @json($workflowChainInfo);

    function renderWorkflowChain(typeId) {
        const wrap = document.getElementById('workflow-chain-wrap');
        const box = document.getElementById('workflow-chain');
        box.innerHTML = '';

        const chain = workflowChainInfo[typeId];
        if (!typeId || !chain || !chain.configured || !chain.steps.length) {
            wrap.style.display = 'none';
            return;
        }

        chain.steps.forEach((step, idx) => {
            if (idx > 0) {
                const arrow = document.createElement('div');
                arrow.className = 'workflow-chain-arrow';
                arrow.textContent = '→';
                box.appendChild(arrow);
            }

            const isSign = step.tipe === 'penandatangan';

            const stepEl = document.createElement('div');
            stepEl.className = 'workflow-chain-step' + (isSign ? ' workflow-chain-step-sign' : '');

            const badge = document.createElement('div');
            badge.className = 'workflow-chain-step-badge';
            badge.textContent = isSign ? '✓' : (step.label.match(/\d+$/)?.[0] ?? String(idx + 1));

            const body = document.createElement('div');
            body.className = 'workflow-chain-step-body';

            const labelEl = document.createElement('div');
            labelEl.className = 'workflow-chain-step-label';
            labelEl.textContent = step.label;

            const nameEl = document.createElement('div');
            nameEl.className = 'workflow-chain-step-name' + (step.manual ? ' is-muted' : '');
            nameEl.textContent = step.who;
            nameEl.title = step.who;

            body.appendChild(labelEl);
            body.appendChild(nameEl);
            stepEl.appendChild(badge);
            stepEl.appendChild(body);
            box.appendChild(stepEl);
        });

        wrap.style.display = 'block';
    }

    function updateSubmitModeStyling(mode) {
        document.getElementById('submit-mode-option-ajukan').classList.toggle('is-selected', mode === 'ajukan');
        document.getElementById('submit-mode-option-internal').classList.toggle('is-selected', mode === 'internal');
    }

    function updateVerifikatorSection() {
        const typeId = document.getElementById('document_type_id').value;
        const info = workflowStepInfo[typeId];
        const mode = document.querySelector('input[name="submit_mode"]:checked').value;

        const ajukanDetail = document.getElementById('ajukan-detail');
        const pickerWrap = document.getElementById('verifikator-picker-wrap');
        const notConfiguredText = document.getElementById('not-configured-text');
        const internalText = document.getElementById('internal-text');
        const ajukanLangsungInput = document.getElementById('ajukan_langsung_hidden');
        const workflowChainWrap = document.getElementById('workflow-chain-wrap');

        updateSubmitModeStyling(mode);

        ajukanDetail.style.display = 'none';
        internalText.style.display = 'none';
        pickerWrap.style.display = 'none';
        notConfiguredText.style.display = 'none';
        workflowChainWrap.style.display = 'none';
        ajukanLangsungInput.value = '0';

        if (mode === 'internal') {
            internalText.style.display = 'block';
            // display:none tidak mencegah <select multiple> ikut terkirim saat submit —
            // kosongkan pilihan supaya tidak diam-diam tetap mengajukan ke verifikator.
            const ts = document.getElementById('verifikator_ids')?.tomselect;
            if (ts) ts.clear();
            return;
        }

        ajukanDetail.style.display = 'block';

        if (!typeId || !info || !info.configured) {
            notConfiguredText.style.display = 'block';
            return;
        }

        renderWorkflowChain(typeId);

        if (info.needsManual) {
            pickerWrap.style.display = 'block';
        } else {
            ajukanLangsungInput.value = '1';
        }
    }

    document.getElementById('document_type_id').addEventListener('change', updateVerifikatorSection);
    document.querySelectorAll('input[name="submit_mode"]').forEach(r => r.addEventListener('change', updateVerifikatorSection));
    document.addEventListener('DOMContentLoaded', updateVerifikatorSection);
</script>

@endsection
