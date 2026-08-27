@extends('layouts.app')

@section('title', 'Buat Dokumen Baru')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('dokumen.index') }}" style="color:var(--text-muted)">Dokumen Saya</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Buat Baru</span>
@endsection

@section('content')

<div class="page-header" style="max-width: 1280px; margin: 0 auto var(--space-8)">
    <h1 class="page-title">Buat Naskah Dinas Baru</h1>
    <p class="page-subtitle">Unggah draft .docx/.pdf, lihat pratinjau secara langsung, lalu pilih alur verifikasi yang sesuai</p>
</div>

<div class="upload-page-grid">
    <div class="card">
        <form method="POST" action="{{ route('dokumen.store') }}" enctype="multipart/form-data">
            @csrf

            <div class="form-section">
                <div class="form-section-header">
                    <div class="form-section-badge">1</div>
                    <div>
                        <div class="form-section-title">Detail Dokumen</div>
                        <div class="form-section-hint">Informasi dasar naskah dinas yang akan dibuat</div>
                    </div>
                </div>

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

                <div class="form-group" style="margin-bottom:0">
                    <label for="keterangan" class="form-label">Keterangan Catatan Tambahan</label>
                    <textarea name="keterangan" id="keterangan" class="form-control" rows="3" placeholder="Catatan internal pengajuan draft...">{{ old('keterangan') }}</textarea>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-header">
                    <div class="form-section-badge">2</div>
                    <div>
                        <div class="form-section-title">Unggah Berkas</div>
                        <div class="form-section-hint">File Word (.docx) akan tampil pratinjaunya di panel sebelah kanan</div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0">
                    <div class="upload-zone" onclick="document.getElementById('file_dokumen').click()" id="upload-box">
                        <div class="upload-zone-icon">📄</div>
                        <div class="upload-zone-text" id="file-label">Klik atau drag & drop file Word (.docx) di sini</div>
                        <div class="upload-zone-hint">Maksimal ukuran file 10MB &middot; format .docx, .doc, atau .pdf</div>
                        <input type="file" name="file_dokumen" id="file_dokumen" style="display:none" accept=".docx,.doc,.pdf" required onchange="updateFileLabel(this)">
                    </div>
                    @error('file_dokumen') <div class="form-error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-header">
                    <div class="form-section-badge">3</div>
                    <div>
                        <div class="form-section-title">Alur Pengajuan</div>
                        <div class="form-section-hint">Tentukan apakah dokumen ini perlu diverifikasi &amp; ditandatangani elektronik</div>
                    </div>
                </div>

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

            <div class="form-section">
                <div class="form-section-header">
                    <div class="form-section-badge">4</div>
                    <div>
                        <div class="form-section-title">Pengaturan Tambahan</div>
                    </div>
                </div>

                <label class="checkbox-label">
                    <input type="checkbox" name="is_rahasia" value="1" {{ old('is_rahasia') ? 'checked' : '' }}>
                    Dokumen Rahasia / Terbatas (Akses terbatas bagi unit/instalasi terkait)
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap: var(--space-3); margin-top: var(--space-7); padding-top: var(--space-6); border-top: 1px solid var(--border-subtle)">
                <a href="{{ route('dokumen.index') }}" class="btn btn-secondary">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan / Ajukan Dokumen</button>
            </div>
        </form>
    </div>

    <div class="preview-panel">
        <div class="preview-panel-header">
            <span>👁️</span> Pratinjau Dokumen
        </div>
        <div class="preview-panel-body">
            <div class="preview-panel-empty" id="preview-empty-state">
                <div class="preview-panel-empty-icon">📄</div>
                <div class="preview-panel-empty-text">Pratinjau akan muncul di sini secara otomatis setelah Anda memilih berkas .docx pada langkah 2.</div>
            </div>
            <div class="docx-paper-wrapper" id="preview-wrapper" style="display:none">
                <div id="live-docx-container" class="docx-render-target"></div>
            </div>
            <div class="preview-panel-note" id="pdf-preview-note" style="display:none">
                <span>ℹ️</span>
                <span>Pratinjau otomatis hanya tersedia untuk berkas <strong>.docx</strong>. Berkas PDF tetap valid untuk diunggah, namun tidak ditampilkan pratinjaunya di sini.</span>
            </div>
        </div>
    </div>
</div>

<script>
    function updateFileLabel(input) {
        const emptyState = document.getElementById('preview-empty-state');
        const previewWrapper = document.getElementById('preview-wrapper');
        const pdfNote = document.getElementById('pdf-preview-note');

        if (input.files && input.files[0]) {
            const file = input.files[0];
            document.getElementById('file-label').innerHTML = '<strong>' + file.name + '</strong> (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
            document.getElementById('upload-box').style.borderColor = 'var(--brand-500)';
            document.getElementById('upload-box').style.background = 'rgba(99,102,241,0.1)';

            emptyState.style.display = 'none';
            previewWrapper.style.display = 'none';
            pdfNote.style.display = 'none';

            if (file.name.toLowerCase().endsWith('.docx')) {
                previewWrapper.style.display = 'flex';
                const container = document.getElementById('live-docx-container');
                container.innerHTML = '<div style="text-align:center; padding:2rem; color:var(--text-muted)">Memuat pratinjau naskah&hellip;</div>';

                if (typeof docx !== 'undefined') {
                    docx.renderAsync(file, container, null, {
                        inWrapper: true,
                        ignoreWidth: false,
                        ignoreHeight: true,
                        breakPages: true,
                        experimental: true,
                    }).catch(() => {
                        container.innerHTML = '<div style="color:#ef4444; padding:1rem; text-align:center">Pratinjau tidak dapat ditampilkan, namun file tetap valid untuk diunggah.</div>';
                    });
                } else {
                    container.innerHTML = '<div style="color:var(--text-muted); padding:1rem; text-align:center">Pustaka pratinjau gagal dimuat. Berkas tetap valid untuk diunggah.</div>';
                }
            } else {
                pdfNote.style.display = 'flex';
            }
        } else {
            emptyState.style.display = 'flex';
            previewWrapper.style.display = 'none';
            pdfNote.style.display = 'none';
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

        chain.steps.forEach((step) => {
            const isSign = step.tipe === 'penandatangan';

            const stepEl = document.createElement('div');
            stepEl.className = 'workflow-chain-step' + (isSign ? ' workflow-chain-step-sign' : '');

            const badge = document.createElement('div');
            badge.className = 'workflow-chain-step-badge';
            badge.textContent = isSign ? '✓' : (step.label.match(/\d+$/)?.[0] ?? '');

            const body = document.createElement('div');
            body.className = 'workflow-chain-step-body';

            const labelEl = document.createElement('div');
            labelEl.className = 'workflow-chain-step-label';
            const labelText = document.createElement('span');
            labelText.textContent = step.label;
            labelEl.appendChild(labelText);
            if (step.commonSub) {
                const subEl = document.createElement('span');
                subEl.className = 'workflow-chain-step-sub';
                subEl.textContent = '· ' + step.commonSub;
                labelEl.appendChild(subEl);
            }
            body.appendChild(labelEl);

            if (step.note) {
                const noteEl = document.createElement('div');
                noteEl.className = 'workflow-chain-step-note' + (!step.manual && !step.people.length ? ' is-warning' : '');
                noteEl.textContent = step.note;
                body.appendChild(noteEl);
            }

            if (step.people && step.people.length) {
                const peopleEl = document.createElement('div');
                peopleEl.className = 'workflow-chain-people';
                step.people.forEach((p) => {
                    const chip = document.createElement('span');
                    chip.className = 'workflow-chain-person';
                    const nameSpan = document.createElement('span');
                    nameSpan.textContent = p.name;
                    chip.appendChild(nameSpan);
                    if (p.sub) {
                        const subSpan = document.createElement('span');
                        subSpan.className = 'workflow-chain-person-sub';
                        subSpan.textContent = '(' + p.sub + ')';
                        chip.appendChild(subSpan);
                    }
                    peopleEl.appendChild(chip);
                });
                body.appendChild(peopleEl);
            }

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
