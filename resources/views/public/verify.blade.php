<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Keaslian Dokumen — SIMPEL-RS</title>
    @vite(['resources/css/app.css'])
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #0a0b14 0%, #0f1020 100%);
            padding: 1.5rem;
        }

        .verify-card {
            background: var(--bg-card);
            border: 1px solid var(--border-default);
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
            width: 64px; height: 64px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1rem;
        }

        .verify-badge.success { background: rgba(34, 197, 94, 0.15); color: #4ade80; border: 2px solid #22c55e; }
        .verify-badge.error { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 2px solid #ef4444; }

        .verify-title { font-family: var(--font-display); font-size: 1.5rem; font-weight: 700; }
        .verify-sub { font-size: 0.875rem; color: var(--text-muted); margin-top: 4px; }

        .info-row {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-subtle);
            font-size: 0.875rem;
        }

        .info-label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
        .info-val { font-weight: 600; color: var(--text-primary); }
    </style>
</head>
<body>

<div class="verify-card">
    @if($signature)
        <div class="verify-header">
            <div class="verify-badge success">✓</div>
            <div class="verify-title" style="color:#4ade80">DOKUMEN SAH & TERVERIFIKASI</div>
            <div class="verify-sub">Sistem Informasi Manajemen Persuratan Elektronik Rumah Sakit</div>
        </div>

        <div class="info-row">
            <div class="info-label">NOMOR SURAT RESMI</div>
            <div class="info-val" style="font-family:monospace; color:var(--brand-300); font-size:1.1rem">
                {{ $signature->document->nomor_surat }}
            </div>
        </div>

        <div class="info-row">
            <div class="info-label">JUDUL NASKAH DINAS</div>
            <div class="info-val">{{ $signature->document->judul }}</div>
        </div>

        <div class="info-row">
            <div class="info-label">JENIS & UNIT PENGUSUL</div>
            <div class="info-val">{{ $signature->document->documentType->nama }} &bull; {{ $signature->document->unit->nama }}</div>
        </div>

        <div class="info-row">
            <div class="info-label">PENANDATANGAN (TTE)</div>
            <div class="info-val">{{ $signature->penandatangan->name }} ({{ $signature->penandatangan->jabatan }})</div>
        </div>

        <div class="info-row">
            <div class="info-label">TANGGAL PENGESAHAN</div>
            <div class="info-val">{{ $signature->ditandatangani_at->translatedFormat('d F Y \j\a\m H:i') }} WIB</div>
        </div>

        <div class="info-row" style="border-bottom:none">
            <div class="info-label">HASH DOKUMEN (SHA-256)</div>
            <div class="info-val" style="font-family:monospace; font-size:0.75rem; word-break:break-all; color:var(--text-muted)">
                {{ $signature->hash_dokumen }}
            </div>
        </div>

        <div style="margin-top: 2rem; padding: 1rem; background: var(--bg-elevated); border-radius: var(--radius-md); text-align:center; font-size:0.78rem; color:var(--text-muted)">
            Dokumen ini dijamin keasliannya dan diterbitkan secara resmi melalui sistem SIMPEL-RS.
        </div>
    @else
        <div class="verify-header">
            <div class="verify-badge error">✕</div>
            <div class="verify-title" style="color:#f87171">DOKUMEN TIDAK DITEMUKAN</div>
            <div class="verify-sub">Token verifikasi QR Code tidak valid atau telah kedaluwarsa.</div>
        </div>
        <div style="text-align:center; color:var(--text-muted); font-size:0.875rem">
            Harap pastikan Anda memindai QR Code resmi dari naskah dinas cetak/digital SIMPEL-RS.
        </div>
    @endif
</div>

</body>
</html>
