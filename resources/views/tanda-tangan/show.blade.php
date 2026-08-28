@extends('layouts.app')

@section('title', 'Pengesahan Elektronik Internal')

@section('breadcrumb')
    <span class="breadcrumb-separator">/</span>
    <a href="{{ route('ttd.index') }}" style="color:var(--text-muted)">Antrian Pengesahan</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current">Pengesahan Internal</span>
@endsection

@section('content')

<div class="page-header">
    <span class="badge badge-purple" style="margin-bottom:8px">Siap Ditandatangani</span>
    <h1 class="page-title">{{ $document->judul }}</h1>
    <p class="page-subtitle">
        Pengusul: <strong>{{ $document->pengusul->name }}</strong> ({{ $document->unit->nama }}) &bull; Jenis: {{ $document->documentType->nama }} &bull; Versi resmi kandidat: v{{ $document->currentVersion->versi }}
    </p>
    @if(auth()->user()->activeDelegation())
        <p class="page-subtitle" style="margin-top:4px">Anda bertindak sebagai {{ strtoupper(auth()->user()->activeDelegation()->tipe) }} untuk <strong>{{ auth()->user()->activeDelegation()->pejabat->name }}</strong>, berlaku {{ auth()->user()->activeDelegation()->berlaku_dari->format('d/m/Y') }}–{{ auth()->user()->activeDelegation()->berlaku_sampai->format('d/m/Y') }}.</p>
    @endif
</div>

<div class="workflow-review-grid" style="display:grid; grid-template-columns: 2fr 1fr; gap: var(--space-6)">

    {{-- Form pengesahan dan dialog konfirmasi --}}
    <div style="display:flex; flex-direction:column; gap: var(--space-6)">

        <div class="card" style="border: 2px solid var(--border-brand); background: var(--bg-card)">
            <div class="card-header">
                <span class="card-title">Proses Pengesahan Elektronik Internal</span>
            </div>

            <div style="padding: var(--space-4); background: rgba(99,102,241,0.08); border-radius: var(--radius-lg); margin-bottom: var(--space-6); border: 1px solid rgba(99,102,241,0.2)">
                <h4 style="font-size:0.95rem; margin-bottom: 4px; color:var(--brand-300)">Metode: Pengesahan Elektronik Internal SIMPEL-RS</h4>
                <p style="font-size:0.8rem; color:var(--text-secondary); line-height:1.5">
                    Ini adalah persetujuan internal rumah sakit, bukan tanda tangan tersertifikasi PSrE/BSrE. Sistem menerbitkan nomor, QR validasi, dan hash SHA-256 dari PDF final yang disimpan permanen.
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
                    <button type="button" class="btn btn-danger btn-lg" onclick="openReturnModal()">
                        Kembalikan ke Verifikator
                    </button>
                </div>
            </form>
        </div>
        {{-- Pratinjau naskah sebelum pengesahan --}}
        <div class="card">
            <div class="card-header">
                <span class="card-title">Pratinjau Lembar Dokumen</span>
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
    document.getElementById('form-tte').addEventListener('submit', function () {
        this.querySelectorAll('button').forEach(button => button.disabled = true);
        this.querySelector('button[type="submit"]').textContent = 'Membuat PDF final…';
    });

    function mintaOtp() {
        const btn = document.getElementById('btn-minta-otp');
        btn.disabled = true;
        btn.innerText = 'Mengirim OTP...';

        fetch("{{ route('ttd.kirim-otp', $document) }}", {
            method: "POST",
            headers: {
                "X-CSRF-TOKEN": "{{ csrf_token() }}",
                "Content-Type": "application/json"
            }
        })
        .then(async r => {
            const data = await r.json();
            if (!r.ok || !data.success) throw new Error(data.message || 'OTP gagal dikirim.');
            return data;
        })
        .then(data => {
            // debug_otp hanya ada di response saat APP_DEBUG=true (local/testing)
            const msg = data.debug_otp ? `${data.message}\n\n[DEBUG] Kode OTP: ${data.debug_otp}` : data.message;
            if (window.Swal) {
                Swal.fire({ icon: 'success', title: 'Kode OTP siap digunakan', text: msg, confirmButtonText: 'Mengerti' });
            } else { alert(msg); }
            btn.innerText = 'Kirim Ulang OTP';
            setTimeout(() => { btn.disabled = false; }, 30000);
        })
        .catch(e => {
            if (window.Swal) Swal.fire({ icon: 'error', title: 'Pengiriman OTP gagal', text: e.message || 'Silakan coba kembali.' });
            else alert(e.message || "Gagal mengirim OTP.");
            btn.disabled = false;
            btn.innerText = 'Kirim Kode OTP Pengesahan';
        });
    }
</script>

{{-- Modal Tolak --}}
<div id="modal-tolak" class="signature-return-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="modal-tolak-title" aria-hidden="true">
    <div class="signature-return-dialog">
        <div class="signature-return-header">
            <div class="signature-return-icon">!</div>
            <div>
                <h3 id="modal-tolak-title">Kembalikan Dokumen ke Verifikator</h3>
                <p>Tinjau kembali dokumen sebelum dikirim untuk perbaikan.</p>
            </div>
            <button type="button" class="signature-return-close" aria-label="Tutup" onclick="closeReturnModal()">&times;</button>
        </div>
        <form method="POST" action="{{ route('ttd.tolak', $document) }}"
              data-native-confirm="Dokumen akan dikembalikan ke verifikator. Lanjutkan tindakan ini?"
              onsubmit="return window.confirm(this.dataset.nativeConfirm)">
            @csrf
            <div class="signature-return-body">
                <p class="signature-return-explanation">
                    Dokumen akan dibuka kembali hanya pada level verifikasi tertinggi:
                    <strong>{{ $returnTarget?->pluck('verifikator.name')->filter()->join(', ') ?: 'target tidak ditemukan' }}</strong>.
                </p>
                <div class="form-group">
                    <label class="form-label">Catatan Penolakan <span style="color:red">*</span></label>
                    <textarea name="alasan_tolak" class="form-control" rows="4" placeholder="Tuliskan catatan perbaikan atau alasan pengembalian (min. 10 karakter)..." required minlength="10"></textarea>
                </div>
            </div>
            <div class="signature-return-footer">
                <button type="button" class="btn btn-secondary" onclick="closeReturnModal()">Batal</button>
                <button type="submit" class="btn btn-danger">Kembalikan Dokumen</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openReturnModal() {
        const modal = document.getElementById('modal-tolak');
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        modal.querySelector('textarea')?.focus();
    }

    function closeReturnModal() {
        const modal = document.getElementById('modal-tolak');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }
</script>

@endsection
