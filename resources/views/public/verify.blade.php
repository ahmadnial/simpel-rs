<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Keaslian TTE Naskah Dinas — SIMPEL-RS (SRIKANDI Standard)</title>
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
        <div class="verify-header">
            <div class="verify-badge success">✓</div>
            <div class="verify-title" style="color:#16a34a">DOKUMEN RESMI SAH & TERVERIFIKASI</div>
            <div class="verify-sub">Validasi Tanda Tangan Elektronik SIMPEL-RS</div>
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
            <div class="info-label">OLEH SIAPA DITANDATANGANI (PEJABAT TTE)</div>
            <div class="info-val" style="color:var(--brand-700)">
                👤 {{ $signature->penandatangan->name }}
                <div style="font-size:0.8rem; color:var(--text-muted); font-weight:normal; margin-top:2px">
                    {{ $signature->penandatangan->jabatan ?? 'Pejabat Penandatangan' }}
                </div>
            </div>
        </div>

        <div class="info-row">
            <div class="info-label">KAPAN DITANDATANGANI (TIMESTAMP TTE)</div>
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
            Dokumen ini dijamin keaslian dan integritas isinya, diterbitkan secara resmi melalui Sistem Informasi Manajemen Persuratan Elektronik Rumah Sakit (SIMPEL-RS).
        </div>
    @else
        <div class="verify-header">
            <div class="verify-badge error">✕</div>
            <div class="verify-title" style="color:#dc2626">DOKUMEN TIDAK DITEMUKAN</div>
            <div class="verify-sub">Token verifikasi QR Code tidak valid atau tidak terdaftar di sistem.</div>
        </div>
        <div style="text-align:center; color:var(--text-muted); font-size:0.875rem">
            Harap pastikan Anda memindai QR Code Barcode TTE resmi dari naskah dinas cetak/digital SIMPEL-RS.
        </div>
    @endif
</div>

</body>
</html>
