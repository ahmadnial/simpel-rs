@extends('layouts.app')

@section('title', 'Buat Dokumen')

@section('breadcrumb')
<span class="breadcrumb-separator">/</span>
<a href="{{ route('dokumen.index') }}" style="color:var(--text-muted)">Dokumen Saya</a>
<span class="breadcrumb-separator">/</span>
<span class="breadcrumb-current">Buat Baru</span>
@endsection

@section('content')
<div class="document-create">
    <header class="document-create-header">
        <div>
            <div class="document-create-eyebrow">Naskah dinas baru</div>
            <h1 class="page-title">Buat Dokumen</h1>
            <p class="page-subtitle">Lengkapi informasi, unggah naskah asli, lalu periksa alur persetujuan dan penempatan elemen template sebelum mengajukan.</p>
        </div>
        <div class="document-create-progress" aria-label="Tahapan pembuatan dokumen">
            <span class="is-active"><b>1</b> Detail</span><i></i><span><b>2</b> Berkas</span><i></i><span><b>3</b> Pengajuan</span>
        </div>
    </header>

    <div class="document-create-grid">
        <form class="document-form-card" method="POST" action="{{ route('dokumen.store') }}" enctype="multipart/form-data">
            @csrf

            <section class="create-section">
                <div class="create-section-heading"><span class="create-section-number">01</span><div><h2>Informasi dokumen</h2><p>Data ini dipakai untuk pengarsipan dan pengisian template naskah.</p></div></div>
                <div class="create-form-grid">
                    <div class="form-group create-form-wide">
                        <label for="document_type_id" class="form-label">Jenis dokumen <span class="required-mark">Wajib</span></label>
                        <select name="document_type_id" id="document_type_id" class="form-control" required>
                            <option value="">Pilih jenis naskah</option>
                            @foreach($documentTypes as $type)
                            <option value="{{ $type->id }}" {{ old('document_type_id') == $type->id ? 'selected' : '' }}>[{{ $type->singkatan }}] {{ $type->nama }} — {{ $type->format_nomor }}</option>
                            @endforeach
                        </select>
                        @error('document_type_id') <div class="form-error">{{ $message }}</div> @enderror
                    </div>
                    <div class="form-group create-form-wide">
                        <label for="judul" class="form-label">Judul dokumen <span class="required-mark">Wajib</span></label>
                        <input type="text" name="judul" id="judul" class="form-control" placeholder="Contoh: SK Kebijakan Pelayanan Medis Tahun 2026" value="{{ old('judul') }}" required>
                        @error('judul') <div class="form-error">{{ $message }}</div> @enderror
                    </div>
                    <div class="form-group create-form-wide">
                        <label for="perihal" class="form-label">Perihal / hal <span class="form-optional">Opsional</span></label>
                        <input type="text" name="perihal" id="perihal" class="form-control" placeholder="Contoh: Pengangkatan Komite Medik" value="{{ old('perihal') }}">
                    </div>
                    <div class="form-group create-form-wide create-notes-field">
                        <label for="keterangan" class="form-label">Catatan tambahan <span class="form-optional">Opsional</span></label>
                        <textarea name="keterangan" id="keterangan" class="form-control" rows="2" placeholder="Catatan internal untuk pengajuan draft…">{{ old('keterangan') }}</textarea>
                    </div>
                </div>
            </section>

            <section class="create-section">
                <div class="create-section-heading"><span class="create-section-number">02</span><div><h2>Unggah naskah asli</h2><p>Format dan susunan halaman dipertahankan di pratinjau.</p></div></div>
                <div class="upload-zone create-upload-zone" id="upload-box" role="button" tabindex="0" aria-controls="file_dokumen">
                    <div class="upload-zone-icon">↑</div>
                    <div><div class="upload-zone-text" id="file-label">Pilih atau tarik berkas ke sini</div><div class="upload-zone-hint">DOCX · maksimal 10 MB</div></div>
                    <span class="upload-zone-action">Pilih berkas</span>
                    <input type="file" name="file_dokumen" id="file_dokumen" class="visually-hidden" accept=".docx,.doc,.pdf" required>
                </div>
                @error('file_dokumen') <div class="form-error">{{ $message }}</div> @enderror
            </section>

            <section class="create-section">
                <div class="create-section-heading"><span class="create-section-number">03</span><div><h2>Tujuan pengajuan</h2><p>Pilih alur yang sesuai; ringkasan pihak yang memeriksa ditampilkan sebelum disimpan.</p></div></div>
                <div class="submit-mode-toggle">
                    <label class="submit-mode-option" id="submit-mode-option-ajukan"><input type="radio" name="submit_mode" value="ajukan" id="mode-ajukan" checked><span class="submit-mode-icon">↗</span><span class="submit-mode-text"><strong>Ajukan untuk verifikasi</strong><small>Diperiksa dan disahkan secara elektronik mengikuti alur kerja.</small></span></label>
                    <label class="submit-mode-option" id="submit-mode-option-internal"><input type="radio" name="submit_mode" value="internal" id="mode-internal"><span class="submit-mode-icon">⌑</span><span class="submit-mode-text"><strong>Arsip internal unit</strong><small>Tersimpan di unit Anda tanpa verifikasi atau pengesahan.</small></span></label>
                </div>
                <input type="hidden" name="ajukan_langsung" id="ajukan_langsung_hidden" value="0">
                <div id="ajukan-detail" class="submission-detail">
                    <div id="workflow-chain-wrap"><div class="workflow-chain-label">Rantai persetujuan</div><div class="workflow-chain" id="workflow-chain"></div></div>
                    <div id="verifikator-picker-wrap" class="verifier-picker"><label for="verifikator_ids" class="form-label">Pilih verifikator awal</label><select name="verifikator_ids[]" id="verifikator_ids" class="form-control" multiple data-tomselect data-placeholder="Cari dan pilih verifikator…">@foreach($verifikators as $v)<option value="{{ $v->id }}">{{ $v->name }} ({{ $v->jabatan ?? 'Verifikator' }}) — {{ $v->unit?->nama }}</option>@endforeach</select><small>Pilih pihak yang memeriksa dokumen pada tahap pertama.</small></div>
                    <p id="not-configured-text" class="workflow-warning" style="display:none">Jenis naskah ini belum memiliki alur persetujuan. Simpan sebagai arsip internal atau hubungi Admin.</p>
                </div>
                <p id="internal-text" class="internal-note" style="display:none">Dokumen akan tersimpan sebagai arsip unit dan tidak diteruskan ke verifikasi maupun pengesahan.</p>
            </section>

            <section class="create-section create-security-section">
                <label class="checkbox-label"><input type="checkbox" name="is_rahasia" value="1" {{ old('is_rahasia') ? 'checked' : '' }}><span><strong>Dokumen rahasia / terbatas</strong><small>Akses dibatasi untuk unit atau instalasi terkait.</small></span></label>
            </section>
            <footer class="create-form-actions"><a href="{{ route('dokumen.index') }}" class="btn btn-secondary">Batal</a><button type="submit" class="btn btn-primary">Simpan dan lanjutkan <span aria-hidden="true">→</span></button></footer>
        </form>

        <aside class="preview-panel create-preview-panel">
            <div class="preview-panel-header"><div><span class="preview-overline">Pratinjau naskah</span><strong id="preview-title">Belum ada berkas</strong></div><span class="preview-status" id="preview-status">Menunggu</span></div>
            <div class="preview-legend"><span><i class="legend-variable"></i> Variabel template</span><span><i class="legend-qr"></i> Posisi QR pengesahan</span></div>
            <div class="preview-panel-body">
                <div class="preview-panel-empty" id="preview-empty-state"><div class="preview-panel-empty-icon">▧</div><div class="preview-panel-empty-text">Pilih berkas <strong>.docx</strong> untuk melihat tata letak naskah, variabel template, dan posisi QR sebelum diajukan.</div></div>
                <div class="docx-paper-wrapper create-docx-wrapper" id="preview-wrapper" style="display:none"><div id="live-docx-container" class="docx-render-target"></div></div>
                <div class="preview-panel-note" id="pdf-preview-note" style="display:none"><span>ℹ</span><span>Berkas PDF tetap dapat diunggah. Untuk menilai posisi variabel dan QR, gunakan naskah sumber <strong>.docx</strong>.</span></div>
            </div>
        </aside>
    </div>
</div>

<script>
const workflowStepInfo = @json($workflowStepInfo), workflowChainInfo = @json($workflowChainInfo);
const fileInput = document.getElementById('file_dokumen'), uploadBox = document.getElementById('upload-box');
function setPreviewState(label, status, state = '') { document.getElementById('preview-title').textContent = label; const node = document.getElementById('preview-status'); node.textContent = status; node.className = 'preview-status ' + state; }
function qrPlaceholder() { const box = document.createElement('span'); box.className = 'template-qr-placeholder'; box.title = 'Dummy QR — posisi pengesahan'; box.setAttribute('aria-label', 'Posisi QR pengesahan'); box.innerHTML = '<svg viewBox="0 0 29 29" aria-hidden="true"><path d="M1 1h9v9H1zm3 3v3h3V4zM19 1h9v9h-9zm3 3v3h3V4zM1 19h9v9H1zm3 3v3h3v-3zM13 1h3v3h-3zm0 6h3v3h-3zm3 3h3v3h-3zm-3 6h3v3h-3zm6-3h3v3h-3zm3 3h6v3h-3v3h3v3h-6v-3h-3zM13 25h6v3h-6z"/></svg><em>QR Pengesahan</em>'; return box; }
function markTemplateTokens(container) { const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT), nodes = []; while (walker.nextNode()) nodes.push(walker.currentNode); nodes.forEach(node => { if (!/\$\{[^}]+\}/.test(node.nodeValue)) return; const fragment = document.createDocumentFragment(), parts = node.nodeValue.split(/(\$\{[^}]+\})/g); parts.forEach(part => { if (!part) return; if (/^\$\{(?:qr_code|barcode_tte)\}$/.test(part)) fragment.appendChild(qrPlaceholder()); else if (/^\$\{[^}]+\}$/.test(part)) { const token = document.createElement('mark'); token.className = 'template-variable'; token.textContent = part; fragment.appendChild(token); } else fragment.appendChild(document.createTextNode(part)); }); node.parentNode.replaceChild(fragment, node); }); }
function renderDocx(file) { const empty = document.getElementById('preview-empty-state'), wrap = document.getElementById('preview-wrapper'), note = document.getElementById('pdf-preview-note'), container = document.getElementById('live-docx-container'); empty.style.display = 'none'; note.style.display = 'none'; wrap.style.display = 'flex'; container.innerHTML = '<div class="preview-loading"><span></span>Memuat tata letak dokumen…</div>'; setPreviewState(file.name, 'Memuat', 'is-loading'); if (typeof docx === 'undefined') { container.innerHTML = '<div class="preview-render-error">Pustaka pratinjau tidak tersedia. Berkas tetap dapat diunggah.</div>'; setPreviewState(file.name, 'Tidak tersedia', 'is-error'); return; } docx.renderAsync(file, container, null, { inWrapper: true, ignoreWidth: false, ignoreHeight: false, breakPages: true, renderHeaders: true, renderFooters: true, renderFootnotes: true, useBase64URL: true, experimental: true }).then(() => { markTemplateTokens(container); setPreviewState(file.name, 'Siap', 'is-ready'); }).catch(() => { container.innerHTML = '<div class="preview-render-error">Pratinjau tidak dapat ditampilkan. Pastikan berkas adalah DOCX yang valid.</div>'; setPreviewState(file.name, 'Gagal dimuat', 'is-error'); }); }
function updateFileLabel(input) { const file = input.files?.[0], empty = document.getElementById('preview-empty-state'), wrap = document.getElementById('preview-wrapper'), note = document.getElementById('pdf-preview-note'); if (!file) { empty.style.display = 'flex'; wrap.style.display = 'none'; note.style.display = 'none'; setPreviewState('Belum ada berkas', 'Menunggu'); return; } document.getElementById('file-label').innerHTML = '<strong>' + file.name + '</strong><small>' + (file.size / 1024 / 1024).toFixed(2) + ' MB</small>'; uploadBox.classList.add('has-file'); if (file.name.toLowerCase().endsWith('.docx')) renderDocx(file); else { empty.style.display = 'none'; wrap.style.display = 'none'; note.style.display = 'flex'; setPreviewState(file.name, 'Tanpa preview', 'is-warning'); } }
uploadBox.addEventListener('click', () => fileInput.click()); uploadBox.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } }); fileInput.addEventListener('change', () => updateFileLabel(fileInput)); ['dragenter','dragover'].forEach(type => uploadBox.addEventListener(type, e => { e.preventDefault(); uploadBox.classList.add('dragover'); })); ['dragleave','drop'].forEach(type => uploadBox.addEventListener(type, e => { e.preventDefault(); uploadBox.classList.remove('dragover'); })); uploadBox.addEventListener('drop', e => { if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; updateFileLabel(fileInput); } });
function renderWorkflowChain(typeId) { const wrap = document.getElementById('workflow-chain-wrap'), box = document.getElementById('workflow-chain'), chain = workflowChainInfo[typeId]; box.innerHTML = ''; if (!typeId || !chain?.configured || !chain.steps.length) { wrap.style.display = 'none'; return; } chain.steps.forEach(step => { const sign = step.tipe === 'penandatangan', el = document.createElement('div'); el.className = 'workflow-chain-step' + (sign ? ' workflow-chain-step-sign' : ''); const badge = document.createElement('div'); badge.className = 'workflow-chain-step-badge'; badge.textContent = sign ? '✓' : (step.label.match(/\d+$/)?.[0] ?? ''); const body = document.createElement('div'); body.className = 'workflow-chain-step-body'; const label = document.createElement('div'); label.className = 'workflow-chain-step-label'; label.textContent = step.label + (step.commonSub ? ' · ' + step.commonSub : ''); body.appendChild(label); if (step.note) { const note = document.createElement('div'); note.className = 'workflow-chain-step-note'; note.textContent = step.note; body.appendChild(note); } if (step.people?.length) { const people = document.createElement('div'); people.className = 'workflow-chain-people'; step.people.forEach(p => { const chip = document.createElement('span'); chip.className = 'workflow-chain-person'; chip.textContent = p.name + (p.sub ? ' (' + p.sub + ')' : ''); people.appendChild(chip); }); body.appendChild(people); } el.append(badge, body); box.appendChild(el); }); wrap.style.display = 'block'; }
function updateSubmitModeStyling(mode) { document.getElementById('submit-mode-option-ajukan').classList.toggle('is-selected', mode === 'ajukan'); document.getElementById('submit-mode-option-internal').classList.toggle('is-selected', mode === 'internal'); }
function updateVerifikatorSection() { const typeId = document.getElementById('document_type_id').value, info = workflowStepInfo[typeId], mode = document.querySelector('input[name="submit_mode"]:checked').value, detail = document.getElementById('ajukan-detail'), picker = document.getElementById('verifikator-picker-wrap'), warning = document.getElementById('not-configured-text'), internal = document.getElementById('internal-text'), direct = document.getElementById('ajukan_langsung_hidden'); updateSubmitModeStyling(mode); detail.style.display = 'none'; internal.style.display = 'none'; picker.style.display = 'none'; warning.style.display = 'none'; direct.value = '0'; if (mode === 'internal') { internal.style.display = 'block'; document.getElementById('verifikator_ids')?.tomselect?.clear(); return; } detail.style.display = 'block'; if (!typeId || !info?.configured) { warning.style.display = 'block'; return; } renderWorkflowChain(typeId); if (info.needsManual) picker.style.display = 'block'; else direct.value = '1'; }
document.getElementById('document_type_id').addEventListener('change', updateVerifikatorSection); document.querySelectorAll('input[name="submit_mode"]').forEach(el => el.addEventListener('change', updateVerifikatorSection)); document.addEventListener('DOMContentLoaded', updateVerifikatorSection);
</script>
@endsection
