@extends('layouts.app')

@section('title', 'Editor Naskah Dinas — ' . $document->judul)

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('dokumen.index') }}" style="color:var(--text-muted)">Dokumen Saya</a>
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('dokumen.show', $document) }}" style="color:var(--text-muted)">{{ Str::limit($document->judul, 20) }}</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Web Editor</span>
@endsection

@section('content')

<div class="page-header" style="display:flex; align-items:center; justify-content:space-between">
    <div>
        <span class="badge badge-yellow" style="margin-bottom:6px">Editor Web Langsung</span>
        <h1 class="page-title">Penyunting Naskah Dinas (Web Editor)</h1>
        <p class="page-subtitle">Sunting teks naskah dinas langsung di browser tanpa perlu unduh-unggah ulang file</p>
    </div>
    <div style="display:flex; gap:10px">
        <a href="{{ route('dokumen.show', $document) }}" class="btn btn-secondary">Batal</a>
        <button type="button" class="btn btn-primary" onclick="simpanEditorContent()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Simpan & Buat Versi Baru
        </button>
    </div>
</div>

<div class="card" style="padding:0; overflow:hidden">

    {{-- Editor Formatting Toolbar --}}
    <div class="editor-toolbar">
        <button type="button" onclick="execCmd('bold')" title="Tebal (Ctrl+B)"><b>B</b></button>
        <button type="button" onclick="execCmd('italic')" title="Miring (Ctrl+I)"><i>I</i></button>
        <button type="button" onclick="execCmd('underline')" title="Garis Bawah (Ctrl+U)"><u>U</u></button>
        <span style="border-right: 1px solid var(--border-default); margin: 0 4px"></span>
        <button type="button" onclick="execCmd('formatBlock', '<h1>')" title="Judul Utama (H1)">H1</button>
        <button type="button" onclick="execCmd('formatBlock', '<h2>')" title="Sub-Judul (H2)">H2</button>
        <button type="button" onclick="execCmd('formatBlock', '<p>')" title="Paragraf Biasa">P</button>
        <span style="border-right: 1px solid var(--border-default); margin: 0 4px"></span>
        <button type="button" onclick="execCmd('justifyLeft')" title="Rata Kiri">⇐ Kiri</button>
        <button type="button" onclick="execCmd('justifyCenter')" title="Rata Tengah">⇔ Tengah</button>
        <button type="button" onclick="execCmd('justifyRight')" title="Rata Kanan">Kanan ⇒</button>
        <button type="button" onclick="execCmd('justifyFull')" title="Rata Kanan Kiri">⇔ Rata</button>
        <span style="border-right: 1px solid var(--border-default); margin: 0 4px"></span>
        <button type="button" onclick="execCmd('insertUnorderedList')" title="Daftar Bullets">&bull; List</button>
        <button type="button" onclick="execCmd('insertOrderedList')" title="Daftar Angka">1. List</button>
        <button type="button" onclick="execCmd('removeFormat')" title="Hapus Format">C</button>
    </div>

    {{-- Form Simpan --}}
    <form id="form-update-editor" method="POST" action="{{ route('dokumen.update-editor', $document) }}">
        @csrf
        <input type="hidden" name="content" id="editor-content-input">
        
        <div style="padding: 16px; background: var(--bg-elevated); border-bottom: 1px solid var(--border-default)">
            <input type="text" name="catatan" class="form-control" placeholder="Catatan perubahan (mis: Memperbaiki penulisan pasal 2)" value="Sunting naskah via Editor Web SIMPEL-RS">
        </div>

        {{-- Paper Editor Canvas --}}
        <div class="docx-paper-wrapper" style="min-height:700px; padding:30px">
            <div id="web-editor-canvas" class="editor-paper" contenteditable="true" spellcheck="false">
                <div style="text-align:center; padding:3rem; color:#888">
                    Memuat naskah dinas ke dalam editor...
                </div>
            </div>
        </div>
    </form>

</div>

<script>
    function execCmd(command, value = null) {
        document.execCommand(command, false, value);
    }

    function simpanEditorContent() {
        const html = document.getElementById('web-editor-canvas').innerHTML;
        document.getElementById('editor-content-input').value = html;
        document.getElementById('form-update-editor').submit();
    }

    document.addEventListener("DOMContentLoaded", function () {
        const previewUrl = "{{ route('dokumen.preview', [$document, $document->currentVersion?->id]) }}";
        const editor = document.getElementById("web-editor-canvas");

        fetch(previewUrl)
            .then(res => res.blob())
            .then(blob => {
                if (typeof docx !== 'undefined') {
                    // Render docx into editable HTML
                    const temp = document.createElement("div");
                    docx.renderAsync(blob, temp, null, {
                        inWrapper: false,
                        ignoreWidth: true,
                        breakPages: false
                    }).then(() => {
                        editor.innerHTML = temp.innerHTML;
                    });
                }
            })
            .catch(err => {
                editor.innerHTML = `
                    <div style="text-align:center; font-family:serif">
                        <h2 style="text-align:center">{{ mb_strtoupper($document->judul) }}</h2>
                        <p style="text-align:center">Nomor: {{ $document->nomor_surat ?? '[Nomor Surat Auto]' }}</p>
                        <hr style="margin:20px 0">
                        <p>Ketikkan isi naskah dinas di sini...</p>
                    </div>
                `;
            });
    });
</script>

@endsection
