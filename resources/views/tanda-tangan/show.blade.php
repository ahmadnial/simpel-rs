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

<div style="display:grid; grid-template-columns: 2fr 1fr; gap: var(--space-6)">

    {{-- Form TTE & Modal --}}
    <div style="display:flex; flex-direction:column; gap: var(--space-6)">

        <div class="card" style="border: 2px solid var(--border-brand); background: var(--bg-card)">
            <div class="card-header">
                <span class="card-title">Proses Pengesahan & Tanda Tangan Elektronik</span>
            </div>

            <div style="padding: var(--space-4); background: rgba(99,102,241,0.08); border-radius: var(--radius-lg); margin-bottom: var(--space-6); border: 1px solid rgba(99,102,241,0.2)">
                <h4 style="font-size:0.95rem; margin-bottom: 4px; color:var(--brand-300)">Metode Pengesahan: Tanda Tangan Elektronik (TTE) Tersertifikasi</h4>
                <p style="font-size:0.8rem; color:var(--text-secondary); line-height:1.5">
                    Sistem menerbitkan nomor naskah dinas resmi, menyematkan QR Code validasi keaslian, dan membubuhkan hash kriptografi SHA-256 pada dokumen PDF final.
                </p>
            </div>

            <div style="display:flex; flex-direction:column; gap: var(--space-4); align-items:center; padding: var(--space-6) 0">
                <button type="button" class="btn btn-warning btn-lg" onclick="mintaOtp()" id="btn-minta-otp">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    Kirim Kode OTP Pengesahan
                </button>
                <div style="font-size:0.78rem; color:var(--text-muted)">Kode otentikasi 6-digit berlaku selama 5 menit.</div>
            </div>

            <form method="POST" action="{{ route('ttd.tandatangani', $document) }}" id="form-tte" style="margin-top: var(--space-4)">
                @csrf
                <div class="form-group">
                    <label for="otp" class="form-label" style="text-align:center">Masukkan 6-Digit Kode OTP</label>
                    <div class="otp-input-group">
                        <input type="text" name="otp" id="otp" class="form-control" style="font-size:1.5rem; text-align:center; letter-spacing:8px; font-weight:700" maxlength="6" placeholder="000000" required>
                    </div>
                </div>
                <div style="display:flex; gap: var(--space-4);">
                    <button type="submit" class="btn btn-primary btn-lg" style="flex:1">
                        Sahkan & Tandatangani Naskah
                    </button>
                    <button type="button" class="btn btn-lg" style="background:var(--bg-elevated); color:var(--text-danger); border:1px solid var(--border-danger);" onclick="document.getElementById('modal-tolak').style.display='flex'">
                        Kembalikan ke Verifikator
                    </button>
                </div>
            </form>
        </div>
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
                </div>
            @endif
        </div>

        {{-- Panel Riwayat Verifikasi --}}
        <div class="card">
            <div class="card-header" style="cursor: pointer;" onclick="document.getElementById('riwayat-panel').classList.toggle('hidden')">
                <span class="card-title" style="display:flex; justify-content:space-between; width:100%">
                    Riwayat Verifikasi
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </span>
            </div>
            <div id="riwayat-panel" style="padding: var(--space-4); max-height: 400px; overflow-y: auto;">
                @php
                    // Tiket 'batal' cuma sisa pool quorum yang kalah cepat begitu rekannya lebih
                    // dulu approve (mis. 3 dari 4 Asesor Internal) — di tahap tanda tangan ini
                    // semua verifikasi sudah kelar, jadi tiket batal itu murni noise riwayat.
                    $resolvedVerifications = $document->verifications->reject(fn ($v) => $v->isDibatalkan());
                @endphp
                @if($resolvedVerifications->count() > 0)
                    <div style="display:flex; flex-direction:column; gap: var(--space-3)">
                    @foreach($resolvedVerifications->sortByDesc('level') as $verif)
                        <div style="padding: 10px; background: var(--bg-body); border-radius: var(--radius-sm); border-left: 3px solid {{ $verif->status == 'disetujui' ? 'var(--brand-500)' : 'var(--text-muted)' }}">
                            <div style="font-size: 0.85rem; font-weight: 600;">Tahap {{ $verif->level }}: {{ $verif->verifikator->name ?? 'Verifikator' }}</div>
                            <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 4px;">
                                Status: <span style="text-transform: capitalize;">{{ $verif->status }}</span>
                                @if($verif->catatan)
                                    <div style="margin-top: 4px; padding-top: 4px; border-top: 1px solid var(--border-light); font-style: italic;">
                                        "{{ $verif->catatan }}"
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    </div>
                @else
                    <div style="font-size: 0.8rem; color: var(--text-muted); text-align: center;">Belum ada riwayat verifikasi</div>
                @endif
            </div>
        </div>
        
        <style>
            .hidden { display: none !important; }
        </style>
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
            // debug_otp hanya ada di response saat APP_DEBUG=true (local/testing)
            const msg = data.debug_otp ? `${data.message}\n\n[DEBUG] Kode OTP: ${data.debug_otp}` : data.message;
            alert(msg);
            location.reload();
        })
        .catch(e => {
            alert("Gagal mengirim OTP.");
            btn.disabled = false;
        });
    }
</script>

{{-- Modal Tolak --}}
<div id="modal-tolak" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:999; align-items:center; justify-content:center;">
    <div class="card" style="width: 100%; max-width: 500px; margin: 1rem;">
        <div class="card-header" style="border-bottom: 1px solid var(--border-light); padding-bottom: 1rem;">
            <h3 style="margin:0; font-size: 1.1rem; color: var(--text-danger);">Kembalikan Dokumen ke Verifikator</h3>
        </div>
        <form method="POST" action="{{ route('ttd.tolak', $document) }}">
            @csrf
            <div style="padding: 1.5rem;">
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1rem;">
                    Dokumen yang dikembalikan akan di-reset ke verifikator tingkat tertinggi untuk ditindaklanjuti.
                </p>
                <div class="form-group">
                    <label class="form-label">Catatan Penolakan <span style="color:red">*</span></label>
                    <textarea name="alasan_tolak" class="form-control" rows="4" placeholder="Tuliskan catatan perbaikan atau alasan pengembalian (min. 10 karakter)..." required minlength="10"></textarea>
                </div>
            </div>
            <div style="padding: 1rem 1.5rem; background: var(--bg-body); border-top: 1px solid var(--border-light); display: flex; justify-content: flex-end; gap: 0.5rem; border-radius: 0 0 var(--radius-lg) var(--radius-lg);">
                <button type="button" class="btn" style="background: white; border: 1px solid var(--border-light);" onclick="document.getElementById('modal-tolak').style.display='none'">Batal</button>
                <button type="submit" class="btn" style="background: var(--text-danger); color: white;">Kembalikan Dokumen</button>
            </div>
        </form>
    </div>
</div>

@endsection
