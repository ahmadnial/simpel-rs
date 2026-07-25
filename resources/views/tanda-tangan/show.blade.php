@extends('layouts.app')

@section('title', 'Tanda Tangan Elektronik')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('ttd.index') }}" style="color:var(--text-muted)">Antrian TTE</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Prosedur TTE</span>
@endsection

@section('content')

<div class="page-header">
    <span class="badge badge-purple" style="margin-bottom:8px">Siap Ditandatangani</span>
    <h1 class="page-title">{{ $document->judul }}</h1>
    <p class="page-subtitle">
        Pengusul: <strong>{{ $document->pengusul->name }}</strong> ({{ $document->unit->nama }}) &bull; Jenis: {{ $document->documentType->nama }}
    </p>
</div>

@if(session('otp_debug'))
<div class="alert alert-warning fade-in" style="margin-bottom: var(--space-6)">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div>
        <strong>DEBUG KODE OTP:</strong> {{ session('otp_debug') }}
    </div>
</div>
@endif

<div style="display:grid; grid-template-columns: 2fr 1fr; gap: var(--space-6)">

    {{-- Form TTE & Modal --}}
    <div style="display:flex; flex-direction:column; gap: var(--space-6)">

        <div class="card" style="border: 2px solid var(--border-brand); background: var(--bg-card)">
            <div class="card-header">
                <span class="card-title">Proses Pengesahan & Tanda Tangan Elektronik</span>
            </div>

            <div style="padding: var(--space-4); background: rgba(99,102,241,0.08); border-radius: var(--radius-lg); margin-bottom: var(--space-6); border: 1px solid rgba(99,102,241,0.2)">
                <h4 style="font-size:0.95rem; margin-bottom: 4px; color:var(--brand-300)">Metode TTE: Internal SHA-256 + QR Code Verifikasi</h4>
                <p style="font-size:0.8rem; color:var(--text-secondary); line-height:1.5">
                    Sistem akan membuat hash kriptografi SHA-256 pada dokumen PDF final, menerbitkan nomor surat resmi secara otomatis, dan membubuhkan QR Code validasi keaslian dokumen.
                </p>
            </div>

            <div style="display:flex; flex-direction:column; gap: var(--space-4); align-items:center; padding: var(--space-6) 0">
                <button type="button" class="btn btn-warning btn-lg" onclick="mintaOtp()" id="btn-minta-otp">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    Kirim Kode OTP Pengesahan
                </button>
                <div style="font-size:0.78rem; color:var(--text-muted)">Kode 6 digit akan dikirimkan untuk otentikasi pengesahan.</div>
            </div>

            <form method="POST" action="{{ route('ttd.tandatangani', $document) }}" id="form-tte" style="margin-top: var(--space-4)">
                @csrf
                <div class="form-group">
                    <label for="otp" class="form-label" style="text-align:center">Masukkan 6-Digit Kode OTP</label>
                    <div class="otp-input-group">
                        <input type="text" name="otp" id="otp" class="form-control" style="font-size:1.5rem; text-align:center; letter-spacing:8px; font-weight:700" maxlength="6" placeholder="000000" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg" style="width:100%">
                    Sah kan & Tandatangani Dokumen
                </button>
            </form>
        {{-- Pratinjau Naskah Sebelum TTE --}}
        <div class="card">
            <div class="card-header">
                <span class="card-title">Pratinjau Lembar Naskah Dinas</span>
            </div>
            <div class="docx-paper-wrapper">
                <x-naskah-preview :document="$document" />
            </div>
        </div>

    </div>

    {{-- File & Info --}}
    <div style="display:flex; flex-direction:column; gap: var(--space-6)">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Berkas Terkait</span>
            </div>
            @if($document->currentVersion)
                <div style="padding: 1rem; background: var(--bg-elevated); border-radius: var(--radius-md); font-size:0.875rem">
                    <div style="font-weight:600">{{ $document->currentVersion->file_name }}</div>
                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px">Ukuran: {{ $document->currentVersion->file_size_human }}</div>
                    <a href="{{ route('dokumen.download', [$document, $document->currentVersion->id]) }}" class="btn btn-secondary btn-sm" style="margin-top:8px; width:100%">
                        Download untuk Pratinjau
                    </a>
                </div>
            @endif
        </div>
    </div>

</div>

<script>
    function mintaOtp() {
        const btn = document.getElementById('btn-minta-otp');
        btn.disabled = true;
        btn.innerText = 'Mengirim OTP...';

        fetch("{{ route('ttd.kirim-otp') }}", {
            method: "POST",
            headers: {
                "X-CSRF-TOKEN": "{{ csrf_token() }}",
                "Content-Type": "application/json"
            }
        })
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            location.reload();
        })
        .catch(e => {
            alert("Gagal mengirim OTP.");
            btn.disabled = false;
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        const previewUrl = "{{ route('dokumen.preview', [$document, $document->currentVersion?->id]) }}";
        const container  = document.getElementById("docx-preview-container");

        fetch(previewUrl)
            .then(res => {
                if (!res.ok) throw new Error("Gagal mengambil berkas.");
                return res.blob();
            })
            .then(blob => {
                if (typeof docx !== 'undefined') {
                    container.innerHTML = "";
                    docx.renderAsync(blob, container, null, {
                        inWrapper: false,
                        ignoreWidth: true,
                        breakPages: true
                    });
                }
            })
            .catch(err => {
                container.innerHTML = '<div style="color:#ef4444; padding:2rem; text-align:center">Gagal memuat pratinjau dokumen.</div>';
            });
    });
</script>

@endsection
