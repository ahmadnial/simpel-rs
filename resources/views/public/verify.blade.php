<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validasi Pengesahan Internal Naskah Dinas — SIMPEL-RS</title>
    @vite(['resources/css/app.css'])
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: var(--bg-base);
            padding: 1.5rem;
        }

        .verify-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-2xl);
            padding: 2.5rem;
            width: 100%;
            max-width: 580px;
            box-shadow: var(--shadow-xl);
        }

        .verify-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .verify-badge {
            width: 72px; height: 72px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 2.2rem;
            margin: 0 auto 1rem;
        }

        .verify-badge.success { background: #f0fdf4; color: #16a34a; border: 2px solid #22c55e; box-shadow: 0 4px 14px rgba(34, 197, 94, 0.2); }
        .verify-badge.error { background: #fef2f2; color: #dc2626; border: 2px solid #ef4444; box-shadow: 0 4px 14px rgba(239, 68, 68, 0.2); }
        .verify-badge.warning { background: #fffbeb; color: #d97706; border: 2px solid #f59e0b; }

        .verify-title { font-family: var(--font-display); font-size: 1.35rem; font-weight: 700; }
        .verify-sub { font-size: 0.85rem; color: var(--text-muted); margin-top: 4px; }

        .info-row {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-subtle);
            font-size: 0.875rem;
        }

        .info-label { font-size: 0.725rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
        .info-val { font-weight: 600; color: var(--text-primary); }
    </style>
</head>
<body>

<div class="verify-card">
    @if($signature)
        @php
            $fileStatus = $fileVerification['status'] ?? 'not_checked';
            $administrativeStatus = $verification['administrative_status'] ?? 'valid';
            $recordVerified = ($verification['valid'] ?? false) && $administrativeStatus === 'valid';
            $headerClass = $recordVerified ? 'success' : 'error';
            $headerTitle = $recordVerified
                ? 'PENGESAHAN SIMPEL-RS TERVERIFIKASI'
                : 'BUKTI PENGESAHAN BERMASALAH';
            $filePanel = match($fileStatus) {
                'match' => [
                    'color' => $recordVerified ? '#166534' : '#92400e',
                    'background' => $recordVerified ? '#f0fdf4' : '#fffbeb',
                    'border' => $recordVerified ? '#86efac' : '#fcd34d',
                    'title' => $recordVerified ? 'FILE RESMI — HASH COCOK' : 'HASH FILE COCOK — BUKTI SISTEM BERMASALAH',
                ],
                'mismatch' => [
                    'color' => '#991b1b',
                    'background' => '#fef2f2',
                    'border' => '#fca5a5',
                    'title' => 'FILE TIDAK COCOK DENGAN DOKUMEN RESMI',
                ],
                default => [
                    'color' => '#92400e',
                    'background' => '#fffbeb',
                    'border' => '#fcd34d',
                    'title' => 'FILE PENGGUNA BELUM DIPERIKSA',
                ],
            };
        @endphp
        <div class="verify-header">
            <div class="verify-badge {{ $headerClass }}">{{ $recordVerified ? '✓' : '!' }}</div>
            <div class="verify-title">{{ $headerTitle }}</div>
            <div class="verify-sub">Status bukti pengesahan elektronik internal, terpisah dari pemeriksaan file pengguna.</div>
        </div>

        <div style="padding:1rem; border:1px solid {{ $filePanel['border'] }}; background:{{ $filePanel['background'] }}; color:{{ $filePanel['color'] }}; border-radius:var(--radius-md); margin-bottom:1rem">
            <strong>{{ $filePanel['title'] }}</strong>
            <div style="margin-top:0.35rem">{{ $fileVerification['message'] ?? 'Belum diperiksa.' }}</div>
            @if(!empty($fileVerification['actual_hash']))
                <div style="font-family:monospace; font-size:0.7rem; word-break:break-all; margin-top:0.5rem">Hash upload: {{ $fileVerification['actual_hash'] }}</div>
            @endif
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; margin-bottom:1rem; font-size:0.78rem">
            <div>Integritas record/PDF server: <strong>{{ ($verification['checks']['pdf_hash'] ?? $integrityValid) ? 'cocok' : 'tidak cocok' }}</strong></div>
            <div>Persetujuan OTP: <strong>{{ ($verification['checks']['otp_receipt_binding'] ?? false) ? 'receipt valid' : ($signature->evidence ? 'tidak valid' : 'legacy/tidak tersedia') }}</strong></div>
            <div>Segel institusi: <strong>{{ ($verification['checks']['institution_signature'] ?? false) ? 'valid' : ($signature->evidence ? 'tidak valid' : 'tidak tersedia') }}</strong></div>
            <div>Status key: <strong>{{ $verification['key_status'] ?? 'unknown' }}</strong></div>
            <div>Waktu: <strong>internal-only</strong></div>
            <div>Audit chain/checkpoint: <strong>{{ ($verification['checks']['audit_chain'] ?? false) && ($verification['checks']['audit_checkpoint'] ?? false) ? 'lengkap & valid' : ($signature->evidence ? 'gap/tidak valid' : 'tidak tersedia') }}</strong></div>
            <div>Immutable storage: <strong>{{ ($verification['checks']['immutable_storage'] ?? false) ? 'receipt & read-back valid' : ($signature->evidence ? 'gap/tidak valid' : 'tidak tersedia') }}</strong></div>
            <div>Status administratif: <strong>{{ $administrativeStatus }}</strong></div>
        </div>

        <div class="info-row">
            <div class="info-label">NOMOR SURAT RESMI</div>
            <div class="info-val" style="font-family:monospace; color:var(--brand-700); font-size:1.1rem">
                {{ $signature->document->nomor_surat ?? '[Nomor Belum Diterbitkan]' }}
            </div>
        </div>

        <div class="info-row">
            <div class="info-label">JUDUL NASKAH DINAS</div>
            <div class="info-val">{{ $signature->document->judul }}</div>
        </div>

        <div class="info-row">
            <div class="info-label">JENIS NASKAH & UNIT PENGUSUL</div>
            <div class="info-val">{{ $signature->document->documentType->nama }} &bull; {{ $signature->document->unit->nama }}</div>
        </div>

        <div class="info-row">
            <div class="info-label">PENANDATANGAN</div>
            <div class="info-val" style="color:var(--brand-700)">
                👤 {{ $signature->penandatangan->name }}
                <div style="font-size:0.8rem; color:var(--text-muted); font-weight:normal; margin-top:2px">
                    {{ $signature->penandatangan->jabatan ?? 'Pejabat Penandatangan' }}
                </div>
                @if(data_get($signature->metadata_tte, 'principal_name'))
                    <div style="font-size:0.8rem; color:var(--text-muted); font-weight:normal; margin-top:4px">
                        Bertindak sebagai {{ strtoupper(data_get($signature->metadata_tte, 'delegation_type')) }} untuk {{ data_get($signature->metadata_tte, 'principal_name') }}
                        ({{ data_get($signature->metadata_tte, 'delegation_from') }} s.d. {{ data_get($signature->metadata_tte, 'delegation_until') }})
                    </div>
                @endif
            </div>
        </div>

        <div class="info-row">
            <div class="info-label">WAKTU PENGESAHAN</div>
            <div class="info-val">
                🕒 {{ $signature->ditandatangani_at->translatedFormat('d F Y \j\a\m H:i:s') }} WIB
            </div>
        </div>

        <div class="info-row" style="border-bottom:none">
            <div class="info-label">HASH INTEGRITAS DOKUMEN (SHA-256)</div>
            <div class="info-val" style="font-family:monospace; font-size:0.75rem; word-break:break-all; color:var(--text-muted)">
                🔒 {{ $signature->hash_dokumen }}
            </div>
        </div>

        <div style="margin-top: 2rem; padding: 1rem; background: var(--bg-elevated); border-radius: var(--radius-md); text-align:center; font-size:0.78rem; color:var(--text-muted); border: 1px solid var(--border-subtle)">
            Status pengesahan di atas memeriksa evidence resmi SIMPEL-RS. Keaslian PDF dari perangkat Anda hanya dinyatakan resmi setelah hash SHA-256 file cocok byte-per-byte dengan dokumen yang disahkan.
        </div>

        <form method="POST" enctype="multipart/form-data" action="{{ route('public.verify.upload', $signature->qr_token) }}" style="margin-top:1rem; padding:1rem; border:1px solid var(--border-subtle); border-radius:var(--radius-md)">
            @csrf
            <label for="pdf" style="display:block; font-weight:600; margin-bottom:0.5rem">Bandingkan PDF dari perangkat Anda</label>
            <input id="pdf" type="file" name="pdf" accept="application/pdf,.pdf" required>
            @error('pdf')<div style="color:#dc2626; margin-top:0.5rem">{{ $message }}</div>@enderror
            <button type="submit" style="display:block; margin-top:0.75rem; padding:0.6rem 1rem">Periksa PDF</button>
        </form>
        @if($signature->evidence?->bundle_path)
            <a href="{{ route('public.verify.bundle', $signature->qr_token) }}" style="display:block; margin-top:1rem; text-align:center">Unduh evidence bundle untuk verifikasi offline</a>
        @endif
    @else
        <div class="verify-header">
            <div class="verify-badge error">✕</div>
            <div class="verify-title" style="color:#dc2626">DOKUMEN TIDAK DITEMUKAN</div>
            <div class="verify-sub">Token verifikasi QR Code tidak valid atau tidak terdaftar di sistem.</div>
        </div>
        <div style="text-align:center; color:var(--text-muted); font-size:0.875rem">
            Pastikan Anda memindai QR Code pengesahan dari naskah dinas cetak/digital SIMPEL-RS.
        </div>
    @endif
</div>

</body>
</html>
